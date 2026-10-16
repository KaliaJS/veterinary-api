<?php

namespace App\Services\Abonnements;

use App\Enums\FormuleSaas;
use App\Enums\TypePack;
use App\Models\Organisation;
use App\Models\OrganisationFacture;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleAbonnement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Abonnement SaaS d'une organisation, réel (16.10.2026) : Stripe Billing
 * fait foi. La carte est saisie dans le Payment Element de Stripe (jamais
 * sur nos serveurs) puis enregistrée comme carte par défaut du client ;
 * la formule est un abonnement Stripe (prix HT, TVA 8,1 % en sus,
 * changement proratisé et facturé aussitôt, résiliation en fin de
 * période) ; un pack de quota est une facture prélevée aussitôt, la
 * quantité n'est ajoutée qu'une fois la facture payée.
 *
 * L'organisation reflète Stripe (formule, cycle, statut, fin de période)
 * et chaque facture Stripe finalisée a sa ligne dans `organisation_factures`
 * (numéro, montants, PDF). Les webhooks relisent l'objet chez Stripe avant
 * de l'appliquer : l'ordre de livraison ne compte pas.
 */
final class AbonnementSaas
{
    private const MARQUES = [
        'visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express',
        'diners' => 'Diners Club', 'discover' => 'Discover', 'jcb' => 'JCB', 'unionpay' => 'UnionPay',
    ];

    public function __construct(private PasserelleAbonnement $stripe) {}

    /* ————— Carte ————— */

    /** @return array{client_secret: string, cle_publique: ?string} */
    public function intentionCarte(Organisation $organisation, ?string $email): array
    {
        $intention = $this->sansErreur('moyen_paiement', fn () => $this->stripe->creerIntentionCarte($this->client($organisation, $email)));

        return ['client_secret' => $intention['client_secret'], 'cle_publique' => config('services.stripe.key')];
    }

    public function enregistrerCarte(Organisation $organisation, string $moyen): void
    {
        $client = $organisation->stripe_client_id
            ?? throw ValidationException::withMessages(['payment_method' => 'Aucune carte en cours d’enregistrement.']);

        $carte = $this->sansErreur('payment_method', fn () => $this->stripe->lireMoyenPaiement($moyen));
        if (($carte['customer'] ?? null) !== $client) {
            throw ValidationException::withMessages(['payment_method' => 'Cette carte n’appartient pas à votre organisation.']);
        }

        $this->sansErreur('payment_method', fn () => $this->stripe->definirCarteParDefaut($client, $moyen));

        $ancienne = $organisation->stripe_moyen_paiement_id;
        $organisation->forceFill([
            'stripe_moyen_paiement_id' => $moyen,
            'moyen_paiement' => self::libelleCarte($carte),
        ])->save();

        if ($ancienne && $ancienne !== $moyen) {
            $this->detacher($ancienne);
        }
    }

    public function supprimerCarte(Organisation $organisation): void
    {
        if ($organisation->stripe_moyen_paiement_id) {
            $this->detacher($organisation->stripe_moyen_paiement_id);
        }

        $organisation->forceFill(['stripe_moyen_paiement_id' => null, 'moyen_paiement' => null])->save();
    }

    /** « Visa •••• 4242 · exp. 08/28 » */
    public static function libelleCarte(array $carte): string
    {
        $c = $carte['card'] ?? [];
        $marque = self::MARQUES[$c['brand'] ?? ''] ?? Str::ucfirst((string) ($c['brand'] ?? 'Carte'));
        $expiration = isset($c['exp_month'], $c['exp_year']) ? sprintf(' · exp. %02d/%02d', $c['exp_month'], $c['exp_year'] % 100) : '';

        return $marque.' •••• '.($c['last4'] ?? '????').$expiration;
    }

    /* ————— Formule ————— */

    /** Souscrit (première fois, ou après une résiliation échue) ou change de formule / de cycle. */
    public function souscrire(Organisation $organisation, FormuleSaas $cible, string $cycle): void
    {
        $depassements = $organisation->depassementsPour($cible);
        if ($depassements !== []) {
            throw ValidationException::withMessages([
                'plan' => 'Limites de la formule cible dépassées : '.implode(' · ', $depassements).'.',
            ]);
        }

        if (! $organisation->stripe_moyen_paiement_id) {
            throw ValidationException::withMessages(['moyen_paiement' => 'Enregistrez d’abord une carte.']);
        }

        $enCours = $organisation->stripe_abonnement_id && $organisation->statut_abonnement !== 'resilie';
        if ($enCours && $organisation->plan === $cible && $organisation->cycle === $cycle && ! $organisation->resilie_le) {
            throw ValidationException::withMessages(['plan' => 'C’est déjà votre formule.']);
        }

        $prix = $this->sansErreur('plan', fn () => $this->stripe->prixFormule(
            'veterinary-'.$cible->value.'-'.$cycle,
            $cible->nom().($cycle === 'an' ? ' (annuel)' : ' (mensuel)'),
            // Un an = douze mois, sans remise (décision du 16.10.2026)
            $cible->prixMensuel() * 100 * ($cycle === 'an' ? 12 : 1),
            $cycle === 'an' ? 'year' : 'month',
        ));
        $metadonnees = ['organisation_id' => (string) $organisation->id, 'formule' => $cible->value, 'cycle' => $cycle];

        $abonnement = $this->sansErreur('paiement', fn () => $enCours
            ? $this->stripe->changerAbonnement($organisation->stripe_abonnement_id, $prix, $metadonnees)
            : $this->stripe->creerAbonnement($organisation->stripe_client_id, $prix, $this->stripe->tauxTva(), $metadonnees, 'abonnement-'.$organisation->id.'-'.Str::uuid()));

        $this->appliquerAbonnement($organisation, $abonnement);
        $this->refleterDerniereFacture($abonnement);
    }

    public function resilier(Organisation $organisation): void
    {
        $this->abonnementEnCours($organisation);
        $this->appliquerAbonnement($organisation, $this->sansErreur('abonnement', fn () => $this->stripe->finDePeriode($organisation->stripe_abonnement_id, true)));
    }

    public function reactiver(Organisation $organisation): void
    {
        $this->abonnementEnCours($organisation);
        $this->appliquerAbonnement($organisation, $this->sansErreur('abonnement', fn () => $this->stripe->finDePeriode($organisation->stripe_abonnement_id, false)));
    }

    /* ————— Packs ————— */

    public function acheterPack(Organisation $organisation, TypePack $type, int $quantite): void
    {
        $prix = $type->prixPour($quantite)
            ?? throw ValidationException::withMessages(['quantite' => 'Ce palier n’existe pas au catalogue des packs.']);

        if ($organisation->plan->limites()[$type->limite()] === null) {
            throw ValidationException::withMessages(['type' => 'Votre formule est déjà illimitée pour ce quota.']);
        }

        if (! $organisation->stripe_moyen_paiement_id) {
            throw ValidationException::withMessages(['moyen_paiement' => 'Enregistrez d’abord une carte.']);
        }

        $facture = $this->sansErreur('paiement', fn () => $this->stripe->facturerAchat(
            $organisation->stripe_client_id,
            $prix * 100,
            $type->libelle($quantite),
            $this->stripe->tauxTva(),
            ['organisation_id' => (string) $organisation->id, 'type' => 'pack', 'pack' => $type->value, 'quantite' => (string) $quantite, 'libelle' => $type->libelle($quantite)],
            'pack-'.$organisation->id.'-'.Str::uuid(),
        ));

        $this->refleterFacture($facture);
    }

    /* ————— Reflets de Stripe ————— */

    /** @param  array<string, mixed>  $abonnement */
    public function appliquerAbonnement(Organisation $organisation, array $abonnement): void
    {
        $element = $abonnement['items']['data'][0] ?? [];
        $fin = isset($element['current_period_end']) ? $this->date($element['current_period_end']) : null;
        $statut = match ($abonnement['status'] ?? null) {
            'active', 'trialing' => 'actif',
            'past_due', 'unpaid' => 'impaye',
            'incomplete' => 'incomplet',
            default => 'resilie',
        };
        $metadonnees = $abonnement['metadata'] ?? [];
        $cycle = $metadonnees['cycle'] ?? null;
        $finAcces = match (true) {
            $statut === 'resilie' => isset($abonnement['ended_at']) ? $this->date($abonnement['ended_at']) : $fin,
            ! empty($abonnement['cancel_at']) => $this->date($abonnement['cancel_at']),
            (bool) ($abonnement['cancel_at_period_end'] ?? false) => $fin,
            default => null,
        };

        $organisation->forceFill([
            'stripe_abonnement_id' => $abonnement['id'],
            'statut_abonnement' => $statut,
            'plan' => FormuleSaas::tryFrom((string) ($metadonnees['formule'] ?? '')) ?? $organisation->plan,
            'cycle' => in_array($cycle, ['mois', 'an'], true) ? $cycle : $organisation->cycle,
            'periode_fin' => $fin,
            'resilie_le' => $finAcces,
        ])->save();
    }

    /**
     * Facture Stripe finalisée → sa ligne (créée ou mise à jour) ; un
     * pack payé ajoute sa quantité, une seule fois.
     *
     * @param  array<string, mixed>  $facture
     */
    public function refleterFacture(array $facture): ?OrganisationFacture
    {
        if (($facture['status'] ?? 'draft') === 'draft') {
            return null;
        }

        $organisation = Organisation::query()->where('stripe_client_id', $facture['customer'] ?? '')->first();
        if (! $organisation) {
            return null;
        }

        $statut = match ($facture['status']) {
            'paid' => 'payee',
            'void' => 'annulee',
            default => 'a_payer',
        };
        $total = (int) ($facture['total'] ?? 0);
        $ht = (int) ($facture['total_excluding_tax'] ?? $total);

        $ligne = DB::transaction(function () use ($organisation, $facture, $statut, $total, $ht): OrganisationFacture {
            $ligne = OrganisationFacture::query()->lockForUpdate()->firstOrNew(['stripe_facture_id' => $facture['id']]);
            $ligne->stripe_facture_id = $facture['id'];
            $ligne->fill([
                'organisation_id' => $organisation->id,
                'numero' => $facture['number'] ?? $facture['id'],
                'emise_le' => $this->date($facture['created'] ?? time()),
                'libelle' => $this->libelle($facture),
                'montant' => $total / 100,
            ])->forceFill([
                'statut' => $statut,
                'montant_ht' => $ht / 100,
                'tva' => ($total - $ht) / 100,
                'pdf_url' => $facture['invoice_pdf'] ?? null,
                'paiement_url' => $statut === 'a_payer' ? ($facture['hosted_invoice_url'] ?? null) : null,
                'metadonnees' => $facture['metadata'] ?? [],
            ]);

            $metadonnees = $facture['metadata'] ?? [];
            $pack = TypePack::tryFrom((string) ($metadonnees['pack'] ?? ''));
            if ($statut === 'payee' && $pack && ! $ligne->quota_applique) {
                Organisation::query()->whereKey($organisation->id)->increment($pack->colonneQuota(), (int) ($metadonnees['quantite'] ?? 0));
                $ligne->quota_applique = true;
            }

            $ligne->save();

            return $ligne;
        });

        return $ligne;
    }

    /** Webhook `customer.subscription.*` : l'état courant relu chez Stripe. */
    public function surAbonnement(array $objet): void
    {
        $organisation = Organisation::query()->where('stripe_client_id', $objet['customer'] ?? '')->first();
        // Un ancien abonnement (remplacé après une résiliation échue) ne touche plus l'organisation
        if (! $organisation || ($organisation->stripe_abonnement_id && $organisation->stripe_abonnement_id !== ($objet['id'] ?? null))) {
            return;
        }

        $this->appliquerAbonnement($organisation, $this->stripe->lireAbonnement($objet['id']));
    }

    /** Webhook `invoice.*` : la facture courante relue chez Stripe. */
    public function surFacture(array $objet): void
    {
        if (! empty($objet['id'])) {
            $this->refleterFacture($this->stripe->lireFacture($objet['id']));
        }
    }

    /* ————— Interne ————— */

    private function client(Organisation $organisation, ?string $email): string
    {
        if ($organisation->stripe_client_id) {
            return $organisation->stripe_client_id;
        }

        $id = $this->stripe->creerClient($organisation->nom, $email, ['organisation_id' => (string) $organisation->id]);
        $organisation->forceFill(['stripe_client_id' => $id])->save();

        return $id;
    }

    private function abonnementEnCours(Organisation $organisation): void
    {
        if (! $organisation->stripe_abonnement_id || $organisation->statut_abonnement === 'resilie') {
            throw ValidationException::withMessages(['abonnement' => 'Aucun abonnement en cours : choisissez une formule.']);
        }
    }

    /** @param  array<string, mixed>  $abonnement */
    private function refleterDerniereFacture(array $abonnement): void
    {
        $facture = $abonnement['latest_invoice'] ?? null;
        if (is_string($facture)) {
            $facture = $this->stripe->lireFacture($facture);
        }
        if (is_array($facture)) {
            $this->refleterFacture($facture);
        }
    }

    /** @param  array<string, mixed>  $facture */
    private function libelle(array $facture): string
    {
        $metadonnees = $facture['metadata'] ?? [];
        if (($metadonnees['type'] ?? null) === 'pack') {
            return (string) ($metadonnees['libelle'] ?? 'Pack de quota');
        }

        $abonnement = $facture['parent']['subscription_details']['metadata'] ?? [];
        $formule = FormuleSaas::tryFrom((string) ($abonnement['formule'] ?? ''));
        // « Pro Cabinet, mensuel » : court, la ligne de la liste reste lisible
        $nom = $formule ? str_replace('Veterinary ', '', $formule->nom()).(($abonnement['cycle'] ?? 'mois') === 'an' ? ', annuel' : ', mensuel') : null;

        return match ($facture['billing_reason'] ?? null) {
            'subscription_create' => $nom ? 'Abonnement '.$nom : 'Abonnement',
            'subscription_cycle' => $nom ? 'Renouvellement '.$nom : 'Renouvellement',
            'subscription_update' => $nom ? 'Passage à '.$nom.' (prorata)' : 'Changement de formule (prorata)',
            default => (string) ($facture['description'] ?? ($nom ? 'Abonnement '.$nom : 'Abonnement')),
        };
    }

    private function date(int $horodatage): string
    {
        return Carbon::createFromTimestamp($horodatage, 'Europe/Zurich')->toDateString();
    }

    private function detacher(string $moyen): void
    {
        try {
            $this->stripe->detacherMoyenPaiement($moyen);
        } catch (ErreurPaiement) {
            // déjà détachée ou supprimée chez Stripe
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $appel
     * @return T
     */
    private function sansErreur(string $champ, callable $appel): mixed
    {
        try {
            return $appel();
        } catch (ErreurPaiement $e) {
            throw ValidationException::withMessages([$champ => $e->getMessage()]);
        }
    }
}
