<?php

namespace App\Services\Abonnements;

use App\Enums\ArticlePlanete;
use App\Enums\FormuleSaas;
use App\Enums\TypePack;
use App\Enums\VerificationOrganisation;
use App\Mail\CarteExpireAvantPrelevement;
use App\Mail\PaiementAbonnementRefuse;
use App\Models\AchatPlanete;
use App\Models\FacturationTranscription;
use App\Models\FactureProprietaire;
use App\Models\Organisation;
use App\Models\OrganisationFacture;
use App\Models\SuggestionDiagnostique;
use App\Models\User;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleAbonnement;
use App\Services\Diagnostic\ConsommationDiagnostic;
use App\Services\Inscription\VerificationIdentite;
use App\Services\Transcription\ConsommationTranscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Abonnements maison (20.10.2026) : Stripe ne fait plus que garder la carte
 * et la prélever ; l'application tient les abonnements et émet ses
 * factures. Deux sortes d'abonnés, aux mêmes colonnes : l'organisation
 * (formules des vétérinaires, mensuelles) et le compte propriétaire (Plus,
 * 12 CHF par an). Aucune TVA : Meteora Systems Sàrl n'y est pas assujettie.
 *
 * Comme chez Netflix ou Claude :
 * - l'abonnement se paie d'avance et se renouvelle à la date anniversaire
 *   (le jour de la souscription ; le dernier jour d'un mois plus court) ;
 * - passer à une formule plus chère prend effet tout de suite : un nouveau
 *   cycle commence, au prix plein moins le crédit du temps non utilisé de
 *   l'ancienne ; une formule moins chère s'applique au renouvellement ;
 * - la résiliation vaut à la fin de la période payée, réactivable d'ici là ;
 * - un renouvellement refusé laisse l'accès et se retente 3, 5 puis 7 jours
 *   plus tard (les administrateurs sont prévenus) ; après le dernier refus,
 *   l'abonnement prend fin.
 * Les minutes de transcription de la période et les suggestions
 * diagnostiques rendues s'ajoutent à la facture du renouvellement (ou à
 * celle du changement de formule, qui clôt la période).
 * Packs de quota et objets de la planète se paient aussitôt.
 *
 * Quand la banque demande au client de confirmer, la facture attend : la
 * page confirme avec Stripe.js puis demande `confirmer` (ou le webhook
 * `payment_intent.succeeded` s'en charge).
 */
final class AbonnementSaas
{
    /** Plus : 12 CHF par an, payé d'avance. */
    public const PLUS_CENTIMES = 1200;

    /**
     * Jours entre deux nouvelles tentatives après un renouvellement refusé :
     * une par jour pendant 3 jours, l'accès reste ouvert ; la dernière
     * refusée, il se ferme. Payé entre-temps, la période commence quand même
     * à l'échéance : aucun jour n'est offert.
     */
    private const RELANCES = [1, 1, 1];

    private const ACHAT_EN_COURS = ['paiement', 'Un paiement est déjà en cours. Patientez un instant.'];

    /**
     * Une demande restée sans réponse est renvoyée telle quelle (même clé,
     * même carte) jusqu'à la réponse de Stripe, qui garde une clé au moins
     * 24 h : pendant ces heures, rien d'autre ne part pour la facture et rien
     * ne s'annule. Au-delà, on ne renvoie plus : la liste de Stripe fait foi
     * (recommandation de Stripe, 26.10.2026).
     */
    private const SUSPENS_HEURES = 23;

    private const EN_VERIFICATION = 'Le paiement est en cours de vérification chez le prestataire : il se termine tout seul dans un instant.';

    /** Alertes quand la carte expire avant le prochain prélèvement : 14 jours avant, puis la veille. */
    private const ALERTE_CARTE_JOURS = [14, 1];

    private const MARQUES = [
        'visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express',
        'diners' => 'Diners Club', 'discover' => 'Discover', 'jcb' => 'JCB', 'unionpay' => 'UnionPay',
    ];

    public function __construct(
        private PasserelleAbonnement $stripe,
        private ConsommationTranscription $transcription,
        private ConsommationDiagnostic $diagnostic,
        private AlerteCleStripe $alerteCle,
    ) {}

    /* ————— Carte ————— */

    /** @return array{client_secret: string, cle_publique: ?string} */
    public function intentionCarte(Organisation|User $abonne, ?string $email): array
    {
        $intention = $this->sansErreur('moyen_paiement', fn () => $this->stripe->creerIntentionCarte($this->client($abonne, $email)));

        return ['client_secret' => $intention['client_secret'], 'cle_publique' => config('services.stripe.key')];
    }

    public function enregistrerCarte(Organisation|User $abonne, string $moyen): void
    {
        $client = $abonne->stripe_client_id
            ?? throw ValidationException::withMessages(['payment_method' => 'Aucune carte en cours d’enregistrement.']);

        $carte = $this->sansErreur('payment_method', fn () => $this->stripe->lireMoyenPaiement($moyen));
        if (($carte['customer'] ?? null) !== $client) {
            throw ValidationException::withMessages(['payment_method' => 'Cette carte n’appartient pas à votre compte.']);
        }

        $this->sansErreur('payment_method', fn () => $this->stripe->definirCarteParDefaut($client, $moyen));

        $ancienne = $abonne->stripe_moyen_paiement_id;
        $abonne->forceFill([
            'stripe_moyen_paiement_id' => $moyen,
            'moyen_paiement' => self::libelleCarte($carte),
            'carte_expire_le' => self::finExpiration($carte),
            'carte_alertee_le' => null,
        ])->save();

        if ($ancienne && $ancienne !== $moyen) {
            $this->detacher($ancienne);
        }
    }

    public function supprimerCarte(Organisation|User $abonne): void
    {
        if ($abonne->stripe_moyen_paiement_id) {
            $this->detacher($abonne->stripe_moyen_paiement_id);
        }

        $abonne->forceFill(['stripe_moyen_paiement_id' => null, 'moyen_paiement' => null, 'carte_expire_le' => null, 'carte_alertee_le' => null])->save();
    }

    /**
     * La banque a remplacé la carte (expiration, perte) et Stripe a mis à
     * jour la carte enregistrée (événement payment_method.automatically_updated) :
     * son libellé et son expiration suivent.
     *
     * @param  array<string, mixed>  $moyen
     */
    public function surCarteMiseAJour(array $moyen): void
    {
        foreach ([Organisation::query(), User::query()] as $requete) {
            $requete->where('stripe_moyen_paiement_id', (string) ($moyen['id'] ?? ''))
                ->get()
                ->each(fn (Organisation|User $abonne) => $abonne->forceFill([
                    'moyen_paiement' => self::libelleCarte($moyen),
                    'carte_expire_le' => self::finExpiration($moyen),
                    'carte_alertee_le' => null,
                ])->save());
        }
    }

    /**
     * Cartes qui expirent avant le prochain prélèvement : les
     * administrateurs (ou le propriétaire) sont prévenus 14 jours avant,
     * puis la veille, une fois chaque fois. Rien n'est prélevé d'avance.
     *
     * @return int Alertes envoyées
     */
    public function alerterCartes(): int
    {
        $aujourdhui = CarbonImmutable::today('Europe/Zurich');
        $envoyees = 0;

        foreach ([Organisation::query(), User::query()] as $requete) {
            $requete->where('statut_abonnement', 'actif')
                ->whereNull('resilie_le')
                ->whereNotNull('carte_expire_le')
                ->whereColumn('carte_expire_le', '<', 'periode_fin')
                ->whereDate('periode_fin', '>', $aujourdhui->toDateString())
                ->whereDate('periode_fin', '<=', $aujourdhui->addDays(max(self::ALERTE_CARTE_JOURS))->toDateString())
                ->get()
                ->each(function (Organisation|User $abonne) use ($aujourdhui, &$envoyees): void {
                    if ($this->alerterCarte($abonne, $aujourdhui)) {
                        $envoyees++;
                    }
                });
        }

        return $envoyees;
    }

    /**
     * L'alerte d'un abonné, si le palier le plus proche déjà atteint ne l'a
     * pas encore eue.
     *
     * @return bool Alerte envoyée
     */
    private function alerterCarte(Organisation|User $abonne, CarbonImmutable $aujourdhui): bool
    {
        $echeance = CarbonImmutable::parse($abonne->periode_fin->toDateString(), 'Europe/Zurich');
        $palier = min(array_filter(self::ALERTE_CARTE_JOURS, fn (int $jours) => $aujourdhui->gte($echeance->subDays($jours))));
        if ($abonne->carte_alertee_le && $abonne->carte_alertee_le->toDateString() >= $echeance->subDays($palier)->toDateString()) {
            return false;
        }

        $destinataires = $abonne instanceof Organisation ? $abonne->emailsAdministrateurs() : array_filter([$abonne->email]);
        if ($destinataires !== []) {
            Mail::to($destinataires)->send(new CarteExpireAvantPrelevement($abonne, $echeance, $abonne instanceof Organisation));
        }
        $abonne->forceFill(['carte_alertee_le' => $aujourdhui->toDateString()])->save();

        return true;
    }

    /**
     * Jour où l'accès se ferme si le renouvellement refusé n'est toujours
     * pas payé : l'échéance, plus les jours de relance.
     */
    public static function fermetureLe(Organisation|User $abonne): ?CarbonImmutable
    {
        if ($abonne->statut_abonnement !== 'impaye' || ! $abonne->periode_fin) {
            return null;
        }

        return CarbonImmutable::parse($abonne->periode_fin->toDateString(), 'Europe/Zurich')->addDays(array_sum(self::RELANCES));
    }

    /** Dernier jour du mois d'expiration de la carte, ou null. */
    private static function finExpiration(array $carte): ?string
    {
        $c = $carte['card'] ?? [];

        return isset($c['exp_month'], $c['exp_year'])
            ? CarbonImmutable::create((int) $c['exp_year'], (int) $c['exp_month'], 1)->endOfMonth()->toDateString()
            : null;
    }

    /** « Visa •••• 4242 · exp. 08/28 » */
    public static function libelleCarte(array $carte): string
    {
        $c = $carte['card'] ?? [];
        $marque = self::MARQUES[$c['brand'] ?? ''] ?? Str::ucfirst((string) ($c['brand'] ?? 'Carte'));
        $expiration = isset($c['exp_month'], $c['exp_year']) ? sprintf(' · exp. %02d/%02d', $c['exp_month'], $c['exp_year'] % 100) : '';

        return $marque.' •••• '.($c['last4'] ?? '????').$expiration;
    }

    /* ————— Formule des organisations ————— */

    /**
     * Première souscription (ou reprise après la fin), changement de
     * formule, ou annulation d'un changement programmé. Rien à payer : null.
     */
    public function souscrire(Organisation $organisation, FormuleSaas $cible): ?Reglement
    {
        return $this->sousVerrouAbonne($organisation, fn () => $this->souscrireSousVerrou($organisation, $cible));
    }

    private function souscrireSousVerrou(Organisation $organisation, FormuleSaas $cible): ?Reglement
    {
        // Rien n'est encaissé avant que la clinique soit vérifiée (MedReg ou à la main)
        if ($organisation->verification !== VerificationOrganisation::Verifiee) {
            throw ValidationException::withMessages(['plan' => 'Votre clinique doit d’abord être vérifiée.']);
        }

        $depassements = $organisation->depassementsPour($cible);
        if ($depassements !== []) {
            throw ValidationException::withMessages([
                'plan' => 'Limites de la formule cible dépassées : '.implode(' · ', $depassements).'.',
            ]);
        }
        $this->exigerCarte($organisation);

        // Une facture due (renouvellement refusé, minutes de transcription
        // d'un abonnement fermé) se règle avant toute nouvelle formule
        $due = $this->factures($organisation)->where('statut', 'a_payer')->oldest('id')->get()
            ->first(fn ($f) => in_array($f->metadonnees['action'] ?? null, ['renouvellement', 'fin'], true));
        if ($due) {
            throw ValidationException::withMessages(['plan' => 'Réglez d’abord la facture '.$due->numero.' en attente.']);
        }

        if (! $this->enCours($organisation)) {
            return $this->demarrer($organisation, $cible->value, $cible->prixMensuel() * 100, self::nomCourt($cible), false);
        }

        if ($organisation->plan === $cible) {
            if (! $organisation->resilie_le && ! $organisation->formule_suivante) {
                throw ValidationException::withMessages(['plan' => 'C’est déjà votre formule.']);
            }
            // Résiliation ou changement programmé annulé : on garde la formule
            $organisation->forceFill(['resilie_le' => null, 'formule_suivante' => null])->save();

            return null;
        }

        if ($cible->prixMensuel() < $organisation->plan->prixMensuel()) {
            // Une montée encore en attente passe d'abord : abandonnée, payée, ou
            // encore à vérifier, et la descente attend (26.10.2026)
            $this->remplacerChangements($organisation);
            // Moins chère : au prochain renouvellement, sans remboursement
            $organisation->forceFill(['formule_suivante' => $cible->value, 'resilie_le' => null])->save();

            return null;
        }

        return $this->monter($organisation, $cible);
    }

    /**
     * Ce que coûterait le passage à `$cible`, sans rien prélever : quand
     * (`maintenant`, `renouvellement`, `rien`), les lignes et le total, pour
     * le récapitulatif avant paiement.
     *
     * @return array{quand: string, date: ?string, lignes: list<array{libelle: string, montant: float}>, total: float}
     */
    public function apercu(Organisation $organisation, FormuleSaas $cible): array
    {
        $chf = fn (array $lignes) => array_map(fn ($l) => ['libelle' => $l['libelle'], 'montant' => $l['montant'] / 100], $lignes);
        $total = fn (array $lignes) => max(0, array_sum(array_column($lignes, 'montant'))) / 100;

        if (! $this->enCours($organisation)) {
            $debut = CarbonImmutable::today('Europe/Zurich');
            $fin = self::echeance($debut, $debut->day, false);
            $lignes = [['libelle' => 'Abonnement '.self::nomCourt($cible).', '.self::periode($debut, $fin), 'montant' => $cible->prixMensuel() * 100]];

            return ['quand' => 'maintenant', 'date' => null, 'lignes' => $chf($lignes), 'total' => $total($lignes)];
        }
        if ($organisation->plan === $cible) {
            return ['quand' => 'rien', 'date' => null, 'lignes' => [], 'total' => 0];
        }
        if ($cible->prixMensuel() < $organisation->plan->prixMensuel()) {
            $lignes = [['libelle' => 'Abonnement '.self::nomCourt($cible).', chaque mois dès le renouvellement', 'montant' => $cible->prixMensuel() * 100]];

            return ['quand' => 'renouvellement', 'date' => $organisation->periode_fin->toDateString(), 'lignes' => $chf($lignes), 'total' => $total($lignes)];
        }

        [$lignes, , , $usage] = $this->lignesMontee($organisation, $cible);
        $toutes = [...$lignes, ...array_map(fn ($u) => ['libelle' => $u['libelle'], 'montant' => $u['montant']], $usage)];

        return ['quand' => 'maintenant', 'date' => null, 'lignes' => $chf($toutes), 'total' => $total($toutes)];
    }

    /** Fin de l'abonnement à la fin de la période payée (propriétaire : retour à Gratuit). */
    public function resilier(Organisation|User $abonne): void
    {
        $this->sousVerrouAbonne($abonne, function () use ($abonne): null {
            if (! $this->enCours($abonne)) {
                throw ValidationException::withMessages(['abonnement' => 'Aucun abonnement en cours.']);
            }
            // Un changement de formule encore en attente : passé, il s'applique
            // d'abord et la résiliation court jusqu'à sa nouvelle échéance ;
            // sinon il est abandonné ; encore à vérifier chez Stripe, la
            // résiliation attend de savoir (26.10.2026)
            $changements = $this->factures($abonne)->where('statut', 'a_payer')->get()
                ->filter(fn ($f) => ($f->metadonnees['action'] ?? null) === 'changement');
            foreach ($changements as $changement) {
                if (! $this->annuler($changement) && $changement->refresh()->statut !== 'payee') {
                    throw ValidationException::withMessages(['abonnement' => 'Un changement de formule est encore en vérification chez le prestataire. Réessayez dans quelques minutes.']);
                }
            }
            $abonne->refresh();
            $abonne->forceFill(['resilie_le' => $abonne->periode_fin])->save();
            if ($abonne instanceof Organisation) {
                $abonne->forceFill(['formule_suivante' => null])->save();
            }

            return null;
        }, ['abonnement', 'Un paiement est déjà en cours. Patientez un instant.']);
    }

    public function reactiver(Organisation|User $abonne): void
    {
        if (! $this->enCours($abonne)) {
            throw ValidationException::withMessages(['abonnement' => 'Aucun abonnement en cours : choisissez une formule.']);
        }
        $abonne->forceFill(['resilie_le' => null])->save();
    }

    /* ————— Plus des propriétaires ————— */

    public function souscrirePlus(User $user): ?Reglement
    {
        return $this->sousVerrouAbonne($user, fn () => $this->souscrirePlusSousVerrou($user));
    }

    private function souscrirePlusSousVerrou(User $user): ?Reglement
    {
        if ($this->enCours($user)) {
            if (! $user->resilie_le) {
                throw ValidationException::withMessages(['formule' => 'Plus est déjà votre offre.']);
            }
            $this->reactiver($user);

            return null;
        }
        if ($user->aPlusApple()) {
            throw ValidationException::withMessages(['formule' => 'Plus est déjà actif, payé par l’App Store sur votre iPhone.']);
        }
        $this->exigerCarte($user);

        return $this->demarrer($user, 'plus', self::PLUS_CENTIMES, 'Veterinary Plus', true);
    }

    /* ————— Achats ponctuels ————— */

    public function acheterPack(Organisation $organisation, TypePack $type, int $quantite): Reglement
    {
        $prix = $type->prixPour($quantite)
            ?? throw ValidationException::withMessages(['quantite' => 'Ce palier n’existe pas au catalogue des packs.']);
        if ($organisation->plan->limites()[$type->limite()] === null) {
            throw ValidationException::withMessages(['type' => 'Votre formule est déjà illimitée pour ce quota.']);
        }
        $this->exigerCarte($organisation);

        return $this->sousVerrouAbonne($organisation, function () use ($organisation, $type, $quantite, $prix): Reglement {
            $this->verifierAchatEnSuspens($organisation, 'pack');
            $facture = $this->creerFacture($organisation, [['libelle' => $type->libelle($quantite), 'montant' => $prix * 100]], [
                'type' => 'pack', 'pack' => $type->value, 'quantite' => (string) $quantite,
            ]);

            return $this->encaisserMaintenant($organisation, $facture);
        }, self::ACHAT_EN_COURS);
    }

    /** Objet acheté une fois, posé sur la planète dès le paiement. */
    public function acheterArticle(User $user, ArticlePlanete $article): Reglement
    {
        if (AchatPlanete::query()->where('user_id', $user->id)->where('article', $article)->exists()) {
            throw ValidationException::withMessages(['article' => 'Cet objet est déjà sur votre planète.']);
        }
        $this->exigerCarte($user);

        return $this->sousVerrouAbonne($user, function () use ($user, $article): Reglement {
            $this->verifierAchatEnSuspens($user, 'planete');
            // Relu sous le verrou : un second clic n'achète pas deux fois le même objet
            if (AchatPlanete::query()->where('user_id', $user->id)->where('article', $article)->exists()) {
                throw ValidationException::withMessages(['article' => 'Cet objet est déjà sur votre planète.']);
            }
            $facture = $this->creerFacture($user, [['libelle' => $article->nom().' pour votre planète', 'montant' => $article->centimes()]], [
                'type' => 'planete', 'article' => $article->value,
            ]);

            return $this->encaisserMaintenant($user, $facture);
        }, self::ACHAT_EN_COURS);
    }

    /**
     * Vérification d'identité payée par le vétérinaire pour son badge
     * (23.10.2026, 2 CHF) : une seule, jamais deux fois.
     */
    public function acheterVerificationIdentite(User $user): Reglement
    {
        if ($user->identite_payee_le !== null || $user->identite_statut !== null) {
            throw ValidationException::withMessages(['identite' => 'Votre vérification d’identité est déjà réglée ou en cours.']);
        }
        if (! $user->gln) {
            throw ValidationException::withMessages(['gln' => 'Indiquez d’abord votre GLN dans votre profil.']);
        }
        $this->exigerCarte($user);

        return $this->sousVerrouAbonne($user, function () use ($user): Reglement {
            $this->verifierAchatEnSuspens($user, 'identite');
            if ($user->identite_payee_le !== null) {
                throw ValidationException::withMessages(['identite' => 'Votre vérification d’identité est déjà réglée ou en cours.']);
            }
            $facture = $this->creerFacture($user, [['libelle' => 'Vérification d’identité (badge vétérinaire vérifié)', 'montant' => VerificationIdentite::PRIX_BADGE_CENTIMES]], [
                'type' => 'identite',
            ]);

            return $this->encaisserMaintenant($user, $facture);
        }, self::ACHAT_EN_COURS);
    }

    /* ————— Factures à régler ————— */

    /** Le client règle une facture en attente, depuis l'application. */
    public function regler(OrganisationFacture|FactureProprietaire $facture): Reglement
    {
        if ($facture->statut !== 'a_payer') {
            throw ValidationException::withMessages(['facture' => 'Cette facture n’attend aucun paiement.']);
        }
        // Un prélèvement déjà parti pour cette facture : jamais un second tant
        // que la banque le traite ; passé entre-temps, la facture est payée
        if ($facture->stripe_paiement_id) {
            $etat = $this->stripe->lirePrelevement($facture->stripe_paiement_id);
            if ($etat['statut'] === 'reussi') {
                $this->payer($facture);

                return new Reglement($facture->refresh(), 'reussi');
            }
            if ($etat['statut'] === 'en_cours') {
                throw ValidationException::withMessages(['facture' => 'Votre banque traite déjà le paiement de cette facture. Attendez sa réponse avant de payer à nouveau.']);
            }
        }
        $abonne = $this->abonneDe($facture);
        $this->exigerCarte($abonne);

        $reglement = $this->encaisser($abonne, $facture, false);
        if ($reglement->statut === 'refuse') {
            // Sans réponse de Stripe : renvoyé chaque minute jusqu'au résultat (26.10.2026)
            if (self::demandeEnSuspens($reglement->facture) !== null) {
                throw ValidationException::withMessages(['paiement' => self::EN_VERIFICATION]);
            }
            throw ValidationException::withMessages(['paiement' => $reglement->message ?? 'La carte a été refusée.']);
        }

        return $reglement;
    }

    /** Après la confirmation du client auprès de sa banque (Stripe.js). */
    public function confirmer(OrganisationFacture|FactureProprietaire $facture): Reglement
    {
        if ($facture->statut === 'payee') {
            return new Reglement($facture, 'reussi');
        }
        if (! $facture->stripe_paiement_id) {
            throw ValidationException::withMessages(['facture' => 'Aucun paiement en cours pour cette facture.']);
        }
        $etat = $this->sansErreur('paiement', fn () => $this->stripe->lirePrelevement($facture->stripe_paiement_id));
        if ($etat['statut'] === 'reussi') {
            $this->payer($facture);
        }

        return new Reglement($facture->refresh(), $etat['statut'], $etat['client_secret'], $etat['message']);
    }

    /** Webhook `payment_intent.succeeded` d'un prélèvement de la plateforme. */
    public function surPrelevement(array $intention): void
    {
        [$type, $id] = array_pad(explode(':', (string) ($intention['metadata']['facture'] ?? '')), 2, null);
        $facture = match ($type) {
            'organisation' => OrganisationFacture::query()->find($id),
            'proprietaire' => FactureProprietaire::query()->find($id),
            default => null,
        };
        $intentionId = $intention['id'] ?? null;
        if (! $facture || ($intention['status'] ?? null) !== 'succeeded' || ! is_string($intentionId)) {
            return;
        }
        if ($facture->stripe_paiement_id === $intentionId) {
            $this->payer($facture);

            return;
        }

        // Un prélèvement que la facture ne connaît pas : sa réponse s'est
        // perdue (délai dépassé), il est passé quand même (24.10.2026). Reconnu
        // à son montant, il paie la facture ; une facture déjà payée par un
        // autre l'a été deux fois, à rendre à la main
        Cache::lock('encaisser:'.$type.':'.$facture->id, 120)->block(15, function () use ($facture, $intention, $intentionId): void {
            $facture->refresh();
            if ((int) ($intention['amount'] ?? -1) !== (int) round((float) $facture->montant * 100) || ($intention['currency'] ?? null) !== 'chf') {
                Log::warning('Prélèvement réussi d’un autre montant que sa facture : ignoré.', ['facture' => $facture->numero, 'prelevement' => $intentionId]);

                return;
            }
            if ($facture->statut === 'payee' && $facture->stripe_paiement_id !== $intentionId) {
                Log::critical('Second prélèvement réussi pour une facture déjà payée : à rembourser.', ['facture' => $facture->numero, 'prelevement' => $intentionId]);

                return;
            }
            $facture->forceFill(['stripe_paiement_id' => $intentionId])->save();
            $this->payer($facture);
        });
    }

    /* ————— Renouvellements (planificateur) ————— */

    /** Échéance atteinte : fin d'un abonnement résilié, ou nouvelle période. */
    public function renouveler(Organisation|User $abonne): void
    {
        // Un seul passage à la fois par abonné, relu sous son verrou : deux
        // passages qui se chevauchent ne prélèvent jamais la même période
        // deux fois (26.10.2026)
        $this->siLibre($abonne, function () use ($abonne): void {
            if ($abonne->statut_abonnement === 'actif' && $abonne->periode_fin
                && $abonne->periode_fin->toDateString() <= CarbonImmutable::today('Europe/Zurich')->toDateString()) {
                $this->renouvelerSousVerrou($abonne);
            }
        });
    }

    private function renouvelerSousVerrou(Organisation|User $abonne): void
    {
        // Un changement de formule jamais confirmé auprès de la banque est
        // abandonné, sans jamais être prélevé. Passé entre-temps, il a ouvert
        // une nouvelle période ; encore à vérifier chez Stripe, rien n'est
        // prélevé avant de savoir, la commande de l'heure suivante revient
        // (26.10.2026)
        $changements = $this->factures($abonne)->where('statut', 'a_payer')->get()
            ->filter(fn ($f) => ($f->metadonnees['action'] ?? null) === 'changement');
        foreach ($changements as $changement) {
            if (! $this->annuler($changement) && $changement->refresh()->statut !== 'payee') {
                return;
            }
        }
        if ($changements->isNotEmpty()) {
            $abonne->refresh();
            if ($abonne->periode_fin->toDateString() > CarbonImmutable::today('Europe/Zurich')->toDateString()) {
                return;
            }
        }
        $echeance = CarbonImmutable::parse($abonne->periode_fin->toDateString(), 'Europe/Zurich');

        // Le prélèvement de cette échéance est déjà parti (la banque le traite
        // encore) : jamais un second, on suit le premier (23.10.2026)
        $parti = $this->factures($abonne)->where('statut', 'a_payer')->whereNotNull('stripe_paiement_id')->latest('id')->get()
            ->first(fn ($f) => ($f->metadonnees['action'] ?? null) === 'renouvellement' && ($f->metadonnees['debut'] ?? null) === $echeance->toDateString());
        if ($parti) {
            $this->suivre($abonne, $parti);

            return;
        }

        // Une facture de cette échéance restée sans prélèvement (Stripe en
        // panne) : reprise, jamais une seconde (26.10.2026)
        $enAttente = $this->factures($abonne)->where('statut', 'a_payer')->latest('id')->get()
            ->first(fn ($f) => ($f->metadonnees['action'] ?? null) === 'renouvellement' && ($f->metadonnees['debut'] ?? null) === $echeance->toDateString());

        if ($abonne->resilie_le && $abonne->resilie_le->toDateString() <= $echeance->toDateString()) {
            // Résilié pendant que cette facture attendait une panne de Stripe :
            // abandonnée (26.10.2026). Passée entre-temps, la période est payée
            // et se termine à la suivante ; encore à vérifier, l'heure suivante
            if ($enAttente && ! $this->annuler($enAttente)) {
                return;
            }
            $this->terminer($abonne);

            return;
        }

        if ($enAttente) {
            $reglement = $this->encaisser($abonne, $enAttente, true);
            if (! $reglement->estPaye() && $reglement->statut !== 'en_cours') {
                $this->echecRenouvellement($abonne, $reglement);
            }

            return;
        }

        if ($abonne instanceof Organisation) {
            $formule = FormuleSaas::tryFrom((string) $abonne->formule_suivante) ?? $abonne->plan;
            [$code, $prix, $nom, $annuel] = [$formule->value, $formule->prixMensuel() * 100, self::nomCourt($formule), false];
        } else {
            [$code, $prix, $nom, $annuel] = ['plus', self::PLUS_CENTIMES, 'Veterinary Plus', true];
        }

        $suivante = self::echeance($echeance, (int) ($abonne->ancre_jour ?: $echeance->day), $annuel);
        $lignes = [['libelle' => 'Abonnement '.$nom.', '.self::periode($echeance, $suivante), 'montant' => $prix]];
        $facture = $this->creerFacture($abonne, $lignes, [
            'type' => 'abonnement', 'action' => 'renouvellement', 'formule' => $code,
            'debut' => $echeance->toDateString(), 'fin' => $suivante->toDateString(),
        ], $echeance, $suivante, $abonne instanceof Organisation ? $this->usageDeLaPeriode($abonne, $echeance) : []);

        $reglement = $this->encaisser($abonne, $facture, true);
        if (! $reglement->estPaye() && $reglement->statut !== 'en_cours') {
            $this->echecRenouvellement($abonne, $reglement);
        }
    }

    /** Nouvelle tentative sur la facture de renouvellement en attente. */
    public function retenter(Organisation|User $abonne): void
    {
        // Comme le renouvellement : relu sous le verrou de l'abonné (26.10.2026)
        $this->siLibre($abonne, function () use ($abonne): void {
            if ($abonne->statut_abonnement === 'impaye' && $abonne->prochaine_tentative_le && ! $abonne->prochaine_tentative_le->isFuture()) {
                $this->retenterSousVerrou($abonne);
            }
        });
    }

    private function retenterSousVerrou(Organisation|User $abonne): void
    {
        $facture = $this->factures($abonne)
            ->where('statut', 'a_payer')
            ->latest('id')
            ->get()
            ->first(fn ($f) => ($f->metadonnees['action'] ?? null) === 'renouvellement');
        if (! $facture) {
            $abonne->forceFill(['statut_abonnement' => 'actif', 'tentatives' => 0, 'prochaine_tentative_le' => null])->save();

            return;
        }

        // Résilié pendant les relances : la facture de l'échéance est abandonnée,
        // rien n'est prélevé (26.10.2026). Passée entre-temps, la période est
        // payée et se termine à la suivante ; encore à vérifier, l'heure suivante
        if ($abonne->resilie_le && $abonne->resilie_le->toDateString() <= (string) ($facture->metadonnees['debut'] ?? '')) {
            if ($this->annuler($facture)) {
                $this->terminer($abonne);
            }

            return;
        }

        // La tentative précédente encore traitée par la banque, ou passée entre-temps
        if ($facture->stripe_paiement_id) {
            $etat = $this->stripe->lirePrelevement($facture->stripe_paiement_id);
            if ($etat['statut'] === 'reussi') {
                $this->payer($facture);

                return;
            }
            if ($etat['statut'] === 'en_cours') {
                return;
            }
        }

        $reglement = $this->encaisser($abonne, $facture, true);
        if (! $reglement->estPaye() && $reglement->statut !== 'en_cours') {
            $this->echecRenouvellement($abonne, $reglement);
        }
    }

    /** Un prélèvement parti : payé, encore traité par la banque, ou refusé. */
    private function suivre(Organisation|User $abonne, OrganisationFacture|FactureProprietaire $facture): void
    {
        $etat = $this->stripe->lirePrelevement((string) $facture->stripe_paiement_id);

        match ($etat['statut']) {
            'reussi' => $this->payer($facture),
            'en_cours' => null,
            default => $this->echecRenouvellement($abonne, new Reglement($facture->refresh(), $etat['statut'], $etat['client_secret'], $etat['message'])),
        };
    }

    /* ————— Interne : cycle ————— */

    private function demarrer(Organisation|User $abonne, string $formule, int $prix, string $nom, bool $annuel): Reglement
    {
        // Une souscription restée en attente de confirmation est remplacée ;
        // réglée entre-temps, ou traitée par la banque, on n'en lance pas une seconde
        $remplacees = $this->factures($abonne)->where('statut', 'a_payer')->get()
            ->filter(fn ($f) => ($f->metadonnees['action'] ?? null) === 'souscription');
        $restees = $remplacees->reject(fn ($f) => $this->annuler($f));
        if ($restees->isNotEmpty()) {
            throw ValidationException::withMessages(['plan' => $restees->contains(fn ($f) => $f->refresh()->statut === 'payee')
                ? 'Une souscription précédente vient d’être réglée, est traitée par votre banque ou reste à vérifier : rechargez la page avant de recommencer.'
                : 'Une souscription précédente est encore traitée par votre banque ou en vérification chez le prestataire. Réessayez dans quelques minutes.']);
        }
        // Relu : la facture annulée a rendu ses frais d'identité à l'organisation
        if ($remplacees->isNotEmpty()) {
            $abonne->refresh();
        }

        $debut = CarbonImmutable::today('Europe/Zurich');
        $fin = self::echeance($debut, $debut->day, $annuel);
        $facture = $this->creerFacture($abonne, [['libelle' => 'Abonnement '.$nom.', '.self::periode($debut, $fin), 'montant' => $prix]], [
            'type' => 'abonnement', 'action' => 'souscription', 'formule' => $formule,
            'debut' => $debut->toDateString(), 'fin' => $fin->toDateString(),
        ], $debut, $fin);

        return $this->encaisserMaintenant($abonne, $facture);
    }

    /** Formule plus chère : nouveau cycle aujourd'hui, crédit du temps non utilisé. */
    private function monter(Organisation $organisation, FormuleSaas $cible): Reglement
    {
        $this->remplacerChangements($organisation);

        [$lignes, $debut, $fin, $usage] = $this->lignesMontee($organisation, $cible);
        $facture = $this->creerFacture($organisation, $lignes, [
            'type' => 'abonnement', 'action' => 'changement', 'formule' => $cible->value,
            'debut' => $debut->toDateString(), 'fin' => $fin->toDateString(),
        ], $debut, $fin, $usage);

        return $this->encaisserMaintenant($organisation, $facture);
    }

    /**
     * Un changement resté en attente de confirmation (autre onglet) est
     * remplacé ; réglé entre-temps, traité par la banque, ou encore en
     * vérification chez Stripe, on n'en lance pas un second (24.10.2026).
     */
    private function remplacerChangements(Organisation $organisation): void
    {
        $remplaces = $this->factures($organisation)->where('statut', 'a_payer')->get()
            ->filter(fn ($f) => ($f->metadonnees['action'] ?? null) === 'changement');
        $restes = $remplaces->reject(fn ($f) => $this->annuler($f));
        if ($restes->isNotEmpty()) {
            throw ValidationException::withMessages(['plan' => $restes->contains(fn ($f) => $f->refresh()->statut === 'payee')
                ? 'Un changement de formule précédent vient d’être réglé, est traité par votre banque ou reste à vérifier : rechargez la page avant de recommencer.'
                : 'Un changement de formule précédent est encore traité par votre banque ou en vérification chez le prestataire. Réessayez dans quelques minutes.']);
        }
        if ($remplaces->isNotEmpty()) {
            $organisation->refresh();
        }
    }

    /**
     * Lignes d'un passage à une formule plus chère : un mois de la nouvelle
     * dès aujourd'hui, moins le temps non utilisé de l'ancienne (au jour
     * près), plus les minutes de transcription de la période qui se clôt.
     *
     * @return array{0: list<array{libelle: string, montant: int}>, 1: CarbonImmutable, 2: CarbonImmutable, 3: list<array<string, mixed>>}
     */
    private function lignesMontee(Organisation $organisation, FormuleSaas $cible): array
    {
        $aujourdhui = CarbonImmutable::today('Europe/Zurich');
        $debutActuel = CarbonImmutable::parse($organisation->periode_debut->toDateString(), 'Europe/Zurich');
        $finActuelle = CarbonImmutable::parse($organisation->periode_fin->toDateString(), 'Europe/Zurich');
        $total = max(1, (int) $debutActuel->diffInDays($finActuelle));
        $restant = max(0, min($total, (int) $aujourdhui->diffInDays($finActuelle)));
        $credit = (int) round($organisation->plan->prixMensuel() * 100 * $restant / $total);

        $fin = self::echeance($aujourdhui, $aujourdhui->day, false);
        $lignes = [['libelle' => 'Abonnement '.self::nomCourt($cible).', '.self::periode($aujourdhui, $fin), 'montant' => $cible->prixMensuel() * 100]];
        if ($credit > 0) {
            $lignes[] = ['libelle' => 'Crédit '.self::nomCourt($organisation->plan).' non utilisé ('.$restant.' jour'.($restant > 1 ? 's' : '').')', 'montant' => -$credit];
        }

        return [$lignes, $aujourdhui, $fin, $this->usageDeLaPeriode($organisation, $aujourdhui)];
    }

    /** Fin d'un abonnement résilié : les minutes de la dernière période sont facturées à part. */
    private function terminer(Organisation|User $abonne): void
    {
        if ($abonne instanceof Organisation) {
            $fin = CarbonImmutable::parse($abonne->periode_fin->toDateString(), 'Europe/Zurich');
            $usage = $this->usageDeLaPeriode($abonne, $fin);
            if ($usage !== []) {
                $facture = $this->creerFacture($abonne, [], ['type' => 'consommation', 'action' => 'fin'], null, null, $usage);
                if ($abonne->stripe_client_id && $abonne->stripe_moyen_paiement_id) {
                    $this->encaisser($abonne, $facture, true);
                }
            }
            $abonne->forceFill(['formule_suivante' => null]);
        } else {
            $abonne->forceFill(['formule' => 'gratuit']);
        }

        $abonne->forceFill([
            'statut_abonnement' => 'resilie',
            'tentatives' => 0,
            'prochaine_tentative_le' => null,
        ])->save();
    }

    private function echecRenouvellement(Organisation|User $abonne, Reglement $reglement): void
    {
        // Stripe en panne ou injoignable : la carte n'a pas été refusée. Ni
        // relance consommée, ni e-mail, ni impayé ; on réessaie à l'heure
        // suivante (26.10.2026)
        if ($reglement->prestataireEnPanne) {
            // Passé malgré l'erreur et déjà visible chez Stripe : payé tout de suite
            $facture = $reglement->facture;
            // Une clé refusée ici l'a déjà été à la recherche faite avant le prélèvement, qui l'a signalée
            $trouve = self::estIncertaine($facture)
                ? rescue(fn () => $this->prelevementIncertainPasse($abonne, $facture, self::cleFacture($facture)), null, false)
                : null;
            if ($trouve !== null && $trouve['statut'] === 'reussi') {
                $facture->forceFill(['stripe_paiement_id' => $trouve['id']])->save();
                $this->payer($facture);

                return;
            }
            // Encore actif : il le reste (rien n'a été refusé), l'échéance passée
            // le ramène à la commande de l'heure suivante. Déjà en relance : la
            // prochaine tentative à l'heure pleine suivante
            if ($abonne->statut_abonnement === 'impaye') {
                $abonne->forceFill(['prochaine_tentative_le' => now()->addHour()->startOfHour()])->save();
            }

            return;
        }

        $tentatives = (int) $abonne->tentatives + 1;
        $dernier = $tentatives > count(self::RELANCES);
        $transcription = null;

        if ($dernier) {
            $fermeture = $this->fermerFauteDePaiement($abonne, $reglement->facture);
            if ($fermeture === false) {
                // Payée entre-temps, traitée par la banque, ou impossible à
                // vérifier (Stripe ne répond pas) : rien ne se ferme et aucun
                // e-mail « accès fermé » ne part ; on regarde de nouveau dans
                // six heures (26.10.2026)
                if ($reglement->facture->refresh()->statut !== 'payee') {
                    $abonne->forceFill(['statut_abonnement' => 'impaye', 'prochaine_tentative_le' => now()->addHours(6)->startOfHour()])->save();
                }

                return;
            }
            $transcription = $fermeture;
        } else {
            $abonne->forceFill([
                'statut_abonnement' => 'impaye',
                'tentatives' => $tentatives,
                'prochaine_tentative_le' => now()->addDays(self::RELANCES[$tentatives - 1])->startOfHour(),
            ])->save();
        }

        $destinataires = $abonne instanceof Organisation ? $abonne->emailsAdministrateurs() : array_filter([$abonne->email]);
        if ($destinataires !== []) {
            Mail::to($destinataires)->send(new PaiementAbonnementRefuse(
                $reglement->facture,
                $reglement->message ?? 'La carte a été refusée.',
                $dernier ? null : $abonne->prochaine_tentative_le,
                self::fermetureLe($abonne),
                $abonne instanceof Organisation,
                $transcription,
            ));
        }
    }

    /**
     * Dernière tentative refusée : l'accès se ferme. La facture de la
     * période qui ne commencera pas est annulée ; une reprise ouvrira une
     * nouvelle période le jour du paiement. Les minutes de transcription
     * déjà consommées restent dues, sur leur propre facture.
     *
     * @return OrganisationFacture|null|false false : rien ne s'est fermé (payée, en cours, ou pas vérifiable)
     */
    private function fermerFauteDePaiement(Organisation|User $abonne, OrganisationFacture|FactureProprietaire $facture): OrganisationFacture|false|null
    {
        // Passée, traitée par la banque ou pas vérifiable : rien ne se ferme
        if (! $this->annuler($facture)) {
            return false;
        }
        $transcription = null;

        if ($abonne instanceof Organisation) {
            $usage = $this->usageDeLaPeriode($abonne, CarbonImmutable::parse($abonne->periode_fin->toDateString(), 'Europe/Zurich'));
            if ($usage !== []) {
                $transcription = $this->creerFacture($abonne, [], ['type' => 'consommation', 'action' => 'fin'], null, null, $usage);
            }
            $abonne->forceFill(['formule_suivante' => null]);
        } else {
            $abonne->forceFill(['formule' => 'gratuit']);
        }

        $abonne->forceFill([
            'statut_abonnement' => 'resilie',
            'tentatives' => 0,
            'prochaine_tentative_le' => null,
        ])->save();

        return $transcription;
    }

    /* ————— Interne : factures et paiements ————— */

    /**
     * Facture numérotée (MS-AAAA-NNNNN), en centimes, sans TVA. Les minutes
     * de transcription jointes sont marquées facturées pour leur période,
     * les suggestions diagnostiques jointes, une à une.
     *
     * @param  list<array{libelle: string, montant: int}>  $lignes
     * @param  array<string, string>  $metadonnees
     * @param  list<array<string, mixed>>  $usage
     */
    private function creerFacture(Organisation|User $abonne, array $lignes, array $metadonnees, ?CarbonImmutable $debut = null, ?CarbonImmutable $fin = null, array $usage = []): OrganisationFacture|FactureProprietaire
    {
        return DB::transaction(function () use ($abonne, $lignes, $metadonnees, $debut, $fin, $usage): OrganisationFacture|FactureProprietaire {
            // La vérification d'identité de qui a inscrit la clinique (23.10.2026) :
            // sur la prochaine facture d'abonnement, une seule fois
            if ($abonne instanceof Organisation && ($metadonnees['type'] ?? null) === 'abonnement' && $abonne->frais_identite_centimes > 0) {
                $lignes[] = ['libelle' => 'Vérification d’identité (Stripe Identity)', 'montant' => (int) $abonne->frais_identite_centimes];
                $metadonnees['frais_identite'] = (string) $abonne->frais_identite_centimes;
                $abonne->forceFill(['frais_identite_centimes' => null])->save();
            }
            $toutes = [...$lignes, ...array_map(fn ($u) => ['libelle' => $u['libelle'], 'montant' => $u['montant']], $usage)];
            $total = array_sum(array_column($toutes, 'montant'));
            $facture = $abonne instanceof Organisation ? new OrganisationFacture : new FactureProprietaire;
            $facture->forceFill([
                $abonne instanceof Organisation ? 'organisation_id' : 'user_id' => $abonne->id,
                'numero' => $this->numero(),
                'emise_le' => CarbonImmutable::today('Europe/Zurich')->toDateString(),
                'libelle' => $toutes[0]['libelle'] ?? 'Facture',
                'lignes' => array_map(fn ($l) => ['libelle' => $l['libelle'], 'montant' => $l['montant'] / 100], $toutes),
                'periode_debut' => $debut?->toDateString(),
                'periode_fin' => $fin?->toDateString(),
                'montant' => max(0, $total) / 100,
                'montant_ht' => max(0, $total) / 100,
                'tva' => null,
                'statut' => 'a_payer',
                'metadonnees' => $metadonnees,
            ])->save();

            foreach ($usage as $u) {
                if ($u['type'] === 'diagnostic') {
                    SuggestionDiagnostique::withoutGlobalScopes()
                        ->whereIn('id', $u['ids'])
                        ->whereNull('organisation_facture_id')
                        ->update(['organisation_facture_id' => $facture->id]);

                    continue;
                }
                FacturationTranscription::query()->create([
                    'organisation_id' => $abonne->id,
                    'periode_debut' => $u['debut']->toDateString(),
                    'periode_fin' => $u['fin']->toDateString(),
                    'secondes' => $u['secondes'],
                    'minutes' => $u['minutes'],
                    'montant_centimes' => $u['montant'],
                    'organisation_facture_id' => $facture->id,
                ]);
            }

            return $facture;
        });
    }

    /** Achat ou souscription en présence du client : un refus annule la facture. */
    private function encaisserMaintenant(Organisation|User $abonne, OrganisationFacture|FactureProprietaire $facture): Reglement
    {
        $reglement = $this->encaisser($abonne, $facture, false);
        if ($reglement->statut === 'refuse') {
            // Pas annulée si le prélèvement est passé malgré une erreur de
            // Stripe, ou n'a pas pu être vérifié (26.10.2026) : jamais une
            // seconde facture prélevée pour le même achat
            if (! $this->annuler($facture)) {
                $facture->refresh();
                if ($facture->statut === 'payee') {
                    return new Reglement($facture, 'reussi');
                }

                throw ValidationException::withMessages(['paiement' => self::EN_VERIFICATION]);
            }
            throw ValidationException::withMessages(['paiement' => $reglement->message ?? 'La carte a été refusée.']);
        }

        return $reglement;
    }

    /**
     * Un achat précédent du même genre resté à vérifier (erreur de Stripe,
     * prélèvement peut-être passé) : jamais un second avant de savoir
     * (26.10.2026). Vérifié sans rien de passé, il est annulé et l'achat
     * continue ; passé, ou toujours impossible à vérifier, l'achat est refusé.
     */
    private function verifierAchatEnSuspens(Organisation|User $abonne, string $type): void
    {
        $enSuspens = $this->factures($abonne)->where('statut', 'a_payer')->get()
            ->filter(fn ($f) => ($f->metadonnees['type'] ?? null) === $type && self::estIncertaine($f));
        foreach ($enSuspens as $facture) {
            if ($this->annuler($facture)) {
                continue;
            }
            throw ValidationException::withMessages(['paiement' => $facture->refresh()->statut === 'payee'
                ? 'Votre achat précédent vient d’être confirmé : il est réglé.'
                : 'Le paiement précédent est encore en vérification chez le prestataire. Réessayez dans une minute.']);
        }
    }

    /**
     * Après une erreur de Stripe au résultat incertain : le prélèvement de
     * cette facture passé ou en cours chez ce client, s'il y en a un
     * (26.10.2026). Lève ErreurPaiement si Stripe ne répond pas.
     *
     * @return array{id: ?string, statut: string, client_secret: ?string, message: ?string}|null
     */
    private function prelevementIncertainPasse(Organisation|User $abonne, OrganisationFacture|FactureProprietaire $facture, string $cle): ?array
    {
        return collect($this->stripe->chercherPrelevements((string) $abonne->stripe_client_id, $cle))
            ->first(fn (array $p) => $p['id'] !== null && $p['id'] !== $facture->stripe_paiement_id
                && in_array($p['statut'], ['reussi', 'en_cours', 'action_requise'], true));
    }

    private function encaisser(Organisation|User $abonne, OrganisationFacture|FactureProprietaire $facture, bool $horsSession): Reglement
    {
        $centimes = (int) round((float) $facture->montant * 100);
        if ($centimes <= 0) {
            $this->payer($facture);

            return new Reglement($facture->refresh(), 'reussi');
        }

        $cle = ($facture instanceof OrganisationFacture ? 'organisation' : 'proprietaire').':'.$facture->id;
        // Un prélèvement à la fois par facture (24.10.2026) : un double clic,
        // ou « Régler » pendant la tentative de la nuit, n'en lance pas deux
        // Plus long qu'un prélèvement et ses deux renvois automatiques (3 × 80 s au pire)
        $verrou = Cache::lock('encaisser:'.$cle, 300);
        if (! $verrou->get()) {
            throw ValidationException::withMessages(['facture' => 'Le paiement de cette facture est déjà en cours. Patientez un instant.']);
        }

        try {
            return $this->encaisserSousVerrou($abonne, $facture->refresh(), $cle, $centimes, $horsSession);
        } finally {
            $verrou->release();
        }
    }

    private function encaisserSousVerrou(Organisation|User $abonne, OrganisationFacture|FactureProprietaire $facture, string $cle, int $centimes, bool $horsSession): Reglement
    {
        if ($facture->statut === 'payee') {
            return new Reglement($facture, 'reussi');
        }
        // Annulée entre-temps (autre passage, autre onglet) : jamais prélevée (26.10.2026)
        if ($facture->statut !== 'a_payer') {
            return new Reglement($facture, 'refuse', null, 'Cette commande est annulée.');
        }
        // La tentative précédente, relue sous le verrou : passée, traitée par la
        // banque, ou en attente d'une confirmation ou refusée, annulée avant
        // d'en lancer une autre (le client ne confirmera jamais les deux)
        if ($facture->stripe_paiement_id) {
            $etat = $this->stripe->lirePrelevement($facture->stripe_paiement_id);
            if (in_array($etat['statut'], ['action_requise', 'refuse'], true)) {
                $etat = $this->stripe->annulerPrelevement($facture->stripe_paiement_id);
            }
            if ($etat['statut'] === 'reussi') {
                $this->payer($facture);

                return new Reglement($facture->refresh(), 'reussi');
            }
            if (in_array($etat['statut'], ['en_cours', 'action_requise'], true)) {
                return new Reglement($facture, $etat['statut'], $etat['client_secret'], $etat['message']);
            }
        }

        // Une tentative précédente a reçu une erreur de Stripe : son résultat
        // est incertain (26.10.2026). Avant d'en lancer une autre avec une
        // nouvelle clé, on cherche chez Stripe un prélèvement de cette facture
        // passé ou en cours, qui est alors repris
        if (self::estIncertaine($facture)) {
            try {
                $trouve = $this->prelevementIncertainPasse($abonne, $facture, $cle);
            } catch (ErreurPaiement $e) {
                // Sans réponse sûre, rien n'est tenté : on réessaiera
                $this->signalerCleRefusee($e, $facture);

                return new Reglement($facture, 'refuse', null, $e->getMessage(), prestataireEnPanne: true);
            }
            if ($trouve) {
                $this->reprendre($facture, $trouve['id']);

                return $this->encaisserSousVerrou($abonne, $facture->refresh(), $cle, $centimes, $horsSession);
            }
        }

        // Une demande envoyée sans réponse : renvoyée telle quelle (même clé,
        // même carte, même mode), jamais une autre, jusqu'à la réponse de
        // Stripe (recommandation de Stripe, 26.10.2026)
        $demande = self::demandeEnSuspens($facture);
        $premierEnvoi = $demande === null;
        if ($demande === null && isset($facture->metadonnees['demande_en_suspens'])) {
            // Sans réponse depuis 23 h : Stripe a pu oublier la clé ; sa liste,
            // lue ci-dessus, n'a rien : une nouvelle tentative, avec une
            // nouvelle clé, jamais l'ancienne (recommandation de Stripe)
            $meta = $facture->metadonnees;
            unset($meta['demande_en_suspens']);
            $facture->forceFill(['metadonnees' => $meta, 'tentatives_prelevement' => $facture->tentatives_prelevement + 1])->save();
        }
        if ($demande === null) {
            // La même pour une même tentative, avec la même carte : une réponse
            // reçue (refus, prélèvement, erreur de Stripe) ou une autre carte,
            // une nouvelle tentative
            $demande = [
                'le' => now()->toIso8601String(),
                'cle' => 'facture-'.$cle.'-'.($facture->stripe_paiement_id ?? 'premier')
                    .($facture->tentatives_prelevement > 0 ? '-t'.$facture->tentatives_prelevement : '')
                    .'-'.$abonne->stripe_moyen_paiement_id,
                'moyen' => (string) $abonne->stripe_moyen_paiement_id,
                'hors_session' => $horsSession,
            ];
            // Notée avant l'envoi : une réponse perdue, ou un serveur arrêté au
            // milieu (déploiement), la laisse à renvoyer
            $facture->forceFill(['metadonnees' => [...$facture->metadonnees ?? [], 'demande_en_suspens' => $demande]])->save();
        }
        $cleIdempotence = $demande['cle'];

        try {
            $resultat = $this->stripe->prelever(
                (string) $abonne->stripe_client_id,
                $demande['moyen'],
                $centimes,
                $facture->numero.' · '.$facture->libelle,
                ['plateforme' => 'veterinary', 'facture' => $cle],
                // Le mode de cet appel : « Régler » après l'essai de la nuit, la
                // passerelle rejoue dans l'autre mode (24.10.2026)
                $horsSession,
                $cleIdempotence,
            );
        } catch (ErreurPaiement $e) {
            // Erreur du prestataire : traitée comme un refus (la facture ne reste pas orpheline)
            Log::warning('Prélèvement impossible.', ['facture' => $facture->numero, 'erreur' => $e->getMessage()]);
            // Sans réponse, 409 ou 5xx, le prélèvement a pu passer : la facture
            // est à vérifier avant d'être annulée ou refaite (26.10.2026). Erreur
            // de Stripe lui-même (5xx) : il la garde sous cette clé 24 h, la
            // tentative suivante en prend une autre
            // Sans réponse ou 409 : la demande reste en suspens, à renvoyer
            // telle quelle. Stripe l'a traitée (5xx, erreur nette) : elle ne
            // l'est plus. Refusée avant tout traitement (429, clé de la
            // plateforme) : au premier envoi, rien n'est parti ; au renvoi,
            // rien n'est dit de la première, elle reste en suspens
            $meta = $facture->metadonnees ?? [];
            $refuseeAvant = $e->statutHttp === 429 || $e->cleRefusee();
            if ($e->erreurGardeeParStripe() || ! ($e->estPassagere() || $e->cleRefusee()) || ($refuseeAvant && $premierEnvoi)) {
                unset($meta['demande_en_suspens']);
            }
            if ($e->resultatIncertain()) {
                $meta['prelevement_incertain'] = true;
            }
            $facture->forceFill([
                'metadonnees' => $meta,
                ...$e->erreurGardeeParStripe() ? ['tentatives_prelevement' => $facture->tentatives_prelevement + 1] : [],
            ])->save();
            // La clé de la plateforme refusée : ni la carte ni le client n'y
            // sont pour rien, la plateforme est prévenue (26.10.2026)
            $this->signalerCleRefusee($e, $facture);
            // Une erreur nette (client inconnu, requête refusée…) reste un refus
            $panne = $e->estPassagere() || $e->cleRefusee();
            $resultat = ['id' => null, 'statut' => 'refuse', 'client_secret' => null, 'message' => $e->getMessage()];
        }
        // Une réponse de Stripe pour cette tentative : plus rien d'incertain
        if (! isset($e) && array_intersect_key($facture->metadonnees ?? [], ['prelevement_incertain' => 1, 'demande_en_suspens' => 1]) !== []) {
            $meta = $facture->metadonnees;
            unset($meta['prelevement_incertain'], $meta['demande_en_suspens']);
            $facture->forceFill(['metadonnees' => $meta])->save();
        }

        // Sans réponse du prestataire, la tentative précédente reste la référence
        if ($resultat['id'] !== null) {
            $facture->forceFill(['stripe_paiement_id' => $resultat['id']])->save();
        }
        if ($resultat['statut'] === 'reussi') {
            $this->payer($facture);
        } elseif ($resultat['statut'] === 'refuse') {
            Log::info('Prélèvement refusé.', ['facture' => $facture->numero, 'message' => $resultat['message']]);
        }

        return new Reglement($facture->refresh(), $resultat['statut'], $resultat['client_secret'], $resultat['message'], prestataireEnPanne: $panne ?? false);
    }

    /** La facture telle que Stripe la connaît (métadonnée `facture`). */
    private static function cleFacture(OrganisationFacture|FactureProprietaire $facture): string
    {
        return ($facture instanceof OrganisationFacture ? 'organisation' : 'proprietaire').':'.$facture->id;
    }

    /**
     * Stripe refuse la clé de la plateforme (absente, 401, 403), à n'importe
     * quel appel : journal critique et alerte du jour (26.10.2026).
     */
    public function signalerCleRefusee(Throwable $e, OrganisationFacture|FactureProprietaire|null $facture = null): void
    {
        if ($e instanceof ErreurPaiement && $e->cleRefusee()) {
            Log::critical('Stripe refuse la clé de la plateforme.', ['statut' => $e->statutHttp, 'facture' => $facture?->numero]);
            $this->alerteCle->signaler((int) $e->statutHttp);
        }
    }

    /** Le prélèvement d'une facture retrouvé chez Stripe : il devient la référence, la demande perdue n'est plus en suspens (26.10.2026). */
    private function reprendre(OrganisationFacture|FactureProprietaire $facture, string $intention): void
    {
        $meta = $facture->metadonnees ?? [];
        unset($meta['demande_en_suspens']);
        $facture->forceFill(['stripe_paiement_id' => $intention, 'metadonnees' => $meta])->save();
    }

    /**
     * La demande envoyée à Stripe sans réponse encore (ou 409), depuis moins
     * de SUSPENS_HEURES : à renvoyer telle quelle (26.10.2026).
     *
     * @return array{le: string, cle: string, moyen: string, hors_session: bool}|null
     */
    private static function demandeEnSuspens(OrganisationFacture|FactureProprietaire $facture): ?array
    {
        $suspens = $facture->metadonnees['demande_en_suspens'] ?? null;
        if (! is_array($suspens) || ! is_string($suspens['le'] ?? null) || ! is_string($suspens['cle'] ?? null)) {
            return null;
        }
        if (! CarbonImmutable::parse($suspens['le'])->addHours(self::SUSPENS_HEURES)->isFuture()) {
            return null;
        }

        return ['le' => $suspens['le'], 'cle' => $suspens['cle'], 'moyen' => (string) ($suspens['moyen'] ?? ''), 'hors_session' => (bool) ($suspens['hors_session'] ?? true)];
    }

    /**
     * Commande de chaque minute : une demande sans réponse depuis au moins une
     * minute est renvoyée telle quelle ; refusée, un achat est annulé comme en
     * présence du client, une facture due (renouvellement, minutes d'un
     * abonnement fermé) reste à régler ; sans réponse depuis SUSPENS_HEURES,
     * la liste de Stripe fait foi (26.10.2026).
     */
    public function verifierPaiementEnSuspens(OrganisationFacture|FactureProprietaire $facture): void
    {
        // Relue : un passage précédent a pu la régler ou l'annuler entre-temps
        $facture->refresh();
        $suspens = $facture->metadonnees['demande_en_suspens'] ?? null;
        if ($facture->statut !== 'a_payer' || ! is_array($suspens)) {
            return;
        }
        if (CarbonImmutable::parse((string) ($suspens['le'] ?? 'now'))->isAfter(now()->subMinute())) {
            return;
        }
        // Résilié pendant que le renouvellement attendait Stripe : rien n'est
        // renvoyé ; le renouvellement de chaque heure tranche (passé chez
        // Stripe, la période est payée ; sinon, rien n'est prélevé et
        // l'abonnement se termine) (26.10.2026)
        $resilie = $this->abonneDe($facture)->resilie_le;
        if (($facture->metadonnees['action'] ?? null) === 'renouvellement' && $resilie
            && $resilie->toDateString() <= (string) ($facture->metadonnees['debut'] ?? '')) {
            return;
        }

        // Un achat que le client attend s'annule s'il ne passe pas ; une
        // facture due (minutes d'un abonnement fermé) reste à régler
        $meta = $facture->metadonnees ?? [];
        $achat = in_array($meta['type'] ?? null, ['pack', 'planete', 'identite'], true)
            || in_array($meta['action'] ?? null, ['souscription', 'changement'], true);

        if (self::demandeEnSuspens($facture) === null) {
            Log::warning('Prélèvement sans réponse de Stripe depuis 23 heures : rapproché de sa liste.', ['facture' => $facture->numero]);
            if ($achat) {
                $this->annuler($facture);

                return;
            }
            $trouve = $this->prelevementIncertainPasse($this->abonneDe($facture), $facture, self::cleFacture($facture));
            if ($trouve !== null) {
                $this->reprendre($facture, $trouve['id']);
                if ($trouve['statut'] === 'reussi') {
                    $this->payer($facture);
                }

                return;
            }
            // Rien chez Stripe : de nouveau à régler, la prochaine tentative avec une nouvelle clé
            unset($meta['demande_en_suspens']);
            $facture->forceFill(['metadonnees' => $meta, 'tentatives_prelevement' => $facture->tentatives_prelevement + 1])->save();

            return;
        }

        $reglement = $this->encaisser($this->abonneDe($facture), $facture, (bool) ($suspens['hors_session'] ?? true));
        if ($achat && $reglement->statut === 'refuse' && ! $reglement->prestataireEnPanne) {
            $this->annuler($facture->refresh());
        }
    }

    /**
     * Une tentative au résultat incertain (erreur de Stripe, avec ou sans
     * réponse, ou demande envoyée sans réponse lue) : à vérifier dans la liste
     * du client.
     */
    private static function estIncertaine(OrganisationFacture|FactureProprietaire $facture): bool
    {
        return $facture->tentatives_prelevement > 0
            || (bool) ($facture->metadonnees['prelevement_incertain'] ?? false)
            || isset($facture->metadonnees['demande_en_suspens']);
    }

    /** Facture payée (une seule fois) et ce qu'elle achète, appliqué. */
    private function payer(OrganisationFacture|FactureProprietaire $facture): void
    {
        DB::transaction(function () use ($facture): void {
            $ligne = $facture::query()->lockForUpdate()->find($facture->id);
            if (! $ligne || $ligne->statut === 'payee') {
                return;
            }
            // Une facture annulée ne s'applique jamais, même si un prélèvement
            // passe après coup : à rendre à la main, signalé (24.10.2026)
            if ($ligne->statut === 'annulee') {
                Log::critical('Prélèvement réussi sur une facture annulée : à rembourser.', ['facture' => $ligne->numero, 'prelevement' => $ligne->stripe_paiement_id]);

                return;
            }
            $abonne = $this->abonneDe($ligne);
            $ligne->forceFill(['statut' => 'payee', 'paye_le' => now(), 'carte' => $abonne->moyen_paiement])->save();
            $this->appliquer($ligne, $abonne);
        });
    }

    private function appliquer(OrganisationFacture|FactureProprietaire $facture, Organisation|User $abonne): void
    {
        $meta = $facture->metadonnees ?? [];
        match ($meta['type'] ?? null) {
            'abonnement' => $this->appliquerPeriode($abonne, $meta),
            'pack' => $this->appliquerPack($facture, $abonne, $meta),
            // Badge du vétérinaire (23.10.2026) : la vérification peut commencer
            'identite' => $abonne instanceof User ? $abonne->forceFill(['identite_payee_le' => now()])->save() : null,
            'planete' => ArticlePlanete::tryFrom((string) ($meta['article'] ?? ''))
                ? AchatPlanete::query()->firstOrCreate(['user_id' => $abonne->id, 'article' => ArticlePlanete::from($meta['article'])])
                : null,
            default => null,
        };
    }

    /** @param  array<string, string>  $meta */
    private function appliquerPeriode(Organisation|User $abonne, array $meta): void
    {
        $debut = CarbonImmutable::parse($meta['debut'], 'Europe/Zurich');
        $abonne->forceFill([
            'statut_abonnement' => 'actif',
            'periode_debut' => $meta['debut'],
            'periode_fin' => $meta['fin'],
            'tentatives' => 0,
            'prochaine_tentative_le' => null,
        ]);
        if (in_array($meta['action'] ?? null, ['souscription', 'changement'], true)) {
            $abonne->forceFill(['ancre_jour' => $debut->day, 'resilie_le' => null]);
        }
        if ($abonne instanceof Organisation) {
            $abonne->forceFill([
                'plan' => FormuleSaas::tryFrom((string) ($meta['formule'] ?? '')) ?? $abonne->plan,
                'cycle' => 'mois',
                'formule_suivante' => null,
            ]);
        } else {
            $abonne->forceFill(['formule' => 'plus']);
        }
        $abonne->save();
    }

    /** @param  array<string, string>  $meta */
    private function appliquerPack(OrganisationFacture $facture, Organisation $organisation, array $meta): void
    {
        $pack = TypePack::tryFrom((string) ($meta['pack'] ?? ''));
        if ($pack && ! $facture->quota_applique) {
            Organisation::query()->whereKey($organisation->id)->increment($pack->colonneQuota(), (int) ($meta['quantite'] ?? 0));
            $facture->forceFill(['quota_applique' => true])->save();
        }
    }

    /** Facture annulée : rien n'est dû, ses minutes de transcription et ses suggestions redeviennent à facturer. */
    /**
     * Annule une facture restée à payer. Son prélèvement en attente de
     * confirmation (3-D Secure ouvert dans un autre onglet) ou refusé est
     * d'abord annulé chez Stripe : il ne pourra plus passer (24.10.2026). Passé entre-temps, la
     * facture est payée, pas annulée ; encore traité par la banque, elle reste.
     * Vrai si la facture est annulée.
     */
    private function annuler(OrganisationFacture|FactureProprietaire $facture): bool
    {
        // Une tentative au résultat incertain (erreur de Stripe) : passée, elle
        // devient la référence, et la facture n'est pas annulée à tort ; sans
        // réponse de Stripe pour le vérifier, elle reste due (26.10.2026)
        if (self::estIncertaine($facture)) {
            try {
                $trouve = $this->prelevementIncertainPasse($this->abonneDe($facture), $facture, self::cleFacture($facture));
            } catch (ErreurPaiement $e) {
                report($e);
                $this->signalerCleRefusee($e, $facture);

                return false;
            }
            if ($trouve) {
                $this->reprendre($facture, $trouve['id']);
            } elseif (self::demandeEnSuspens($facture) !== null) {
                // Une demande sans réponse, renvoyée jusqu'à la réponse de
                // Stripe : rien ne s'annule avant de savoir. Annuler ne prélève
                // jamais (26.10.2026)
                return false;
            }
        }
        if ($facture->stripe_paiement_id) {
            $etat = $this->stripe->lirePrelevement($facture->stripe_paiement_id);
            // Refusé, il se confirme encore avec une autre carte (Stripe.js) :
            // annulé lui aussi (24.10.2026)
            if (in_array($etat['statut'], ['action_requise', 'refuse'], true)) {
                $etat = $this->stripe->annulerPrelevement($facture->stripe_paiement_id);
            }
            if ($etat['statut'] === 'reussi') {
                $this->payer($facture);

                return false;
            }
            if ($etat['statut'] === 'en_cours') {
                return false;
            }
        }
        $facture->forceFill(['statut' => 'annulee'])->save();
        if ($facture instanceof OrganisationFacture) {
            // La vérification d'identité attend la facture suivante
            if (($frais = (int) ($facture->metadonnees['frais_identite'] ?? 0)) > 0) {
                Organisation::query()->whereKey($facture->organisation_id)->update(['frais_identite_centimes' => $frais]);
            }
            FacturationTranscription::query()->where('organisation_facture_id', $facture->id)->delete();
            SuggestionDiagnostique::withoutGlobalScopes()->where('organisation_facture_id', $facture->id)->update(['organisation_facture_id' => null]);
        }

        return true;
    }

    /**
     * Consommation de la période en cours, jusqu'à `$fin`, pas encore
     * facturée : une ligne pour les minutes de transcription, une pour les
     * suggestions diagnostiques rendues, ou rien.
     *
     * @return list<array<string, mixed>>
     */
    private function usageDeLaPeriode(Organisation $organisation, CarbonImmutable $fin): array
    {
        if (! $organisation->periode_debut) {
            return [];
        }
        $debut = CarbonImmutable::parse($organisation->periode_debut->toDateString(), 'Europe/Zurich');
        $usage = [];

        $deja = FacturationTranscription::query()
            ->where('organisation_id', $organisation->id)
            ->whereDate('periode_debut', $debut->toDateString())
            ->exists();
        $secondes = $this->transcription->secondes($organisation->id, $debut, $fin);
        if (! $deja && $secondes > 0) {
            $minutes = ConsommationTranscription::minutes($secondes);
            $usage[] = [
                'type' => 'transcription',
                'libelle' => 'Transcription des consultations, '.self::periode($debut, $fin).' : '.$minutes.' min',
                'montant' => $this->transcription->montant($secondes),
                'secondes' => $secondes,
                'minutes' => $minutes,
                'debut' => $debut,
                'fin' => $fin,
            ];
        }

        $suggestions = $this->diagnostic->aFacturer($organisation, $fin);
        if ($suggestions->isNotEmpty()) {
            $nombre = $suggestions->count();
            $usage[] = [
                'type' => 'diagnostic',
                'libelle' => 'Suggestions diagnostiques, '.self::periode($debut, $fin).' : '.$nombre.' suggestion'.($nombre > 1 ? 's' : ''),
                'montant' => (int) $suggestions->sum('montant_centimes'),
                'ids' => $suggestions->modelKeys(),
            ];
        }

        return $usage;
    }

    /** Numéro suivant de la plateforme, sans trou ni doublon : MS-2026-00001. */
    private function numero(): string
    {
        $annee = (int) now('Europe/Zurich')->year;

        return DB::transaction(function () use ($annee): string {
            DB::table('sequence_factures_plateforme')->insertOrIgnore(['annee' => $annee, 'prochain' => 1]);
            $prochain = (int) DB::table('sequence_factures_plateforme')->where('annee', $annee)->lockForUpdate()->value('prochain');
            DB::table('sequence_factures_plateforme')->where('annee', $annee)->update(['prochain' => $prochain + 1]);

            return sprintf('MS-%d-%05d', $annee, $prochain);
        });
    }

    /* ————— Interne : divers ————— */

    /**
     * Échéance suivante à la date anniversaire : le jour d'ancrage du mois
     * (ou de l'année) suivant, le dernier jour d'un mois plus court.
     */
    public static function echeance(CarbonImmutable $depuis, int $ancre, bool $annuel): CarbonImmutable
    {
        $mois = $depuis->startOfMonth()->addMonthsNoOverflow($annuel ? 12 : 1);

        return $mois->day(min($ancre, $mois->daysInMonth));
    }

    /** « du 20.10.2026 au 19.11.2026 » : le dernier jour couvert, la veille de l'échéance */
    private static function periode(CarbonImmutable $debut, CarbonImmutable $fin): string
    {
        return 'du '.$debut->format('d.m.Y').' au '.$fin->subDay()->format('d.m.Y');
    }

    /** « Pro Cabinet » */
    private static function nomCourt(FormuleSaas $formule): string
    {
        return str_replace('Veterinary ', '', $formule->nom());
    }

    private function enCours(Organisation|User $abonne): bool
    {
        return in_array($abonne->statut_abonnement, ['actif', 'impaye'], true) && $abonne->periode_fin !== null;
    }

    private function exigerCarte(Organisation|User $abonne): void
    {
        if (! $abonne->stripe_client_id || ! $abonne->stripe_moyen_paiement_id) {
            throw ValidationException::withMessages(['moyen_paiement' => 'Enregistrez d’abord une carte.']);
        }
    }

    /**
     * Une souscription ou un changement de formule à la fois par abonné
     * (24.10.2026) : deux onglets, ou un double clic, ne créent pas deux
     * factures prélevées chacune. L'abonné est relu sous le verrou : les
     * contrôles portent sur l'état laissé par la requête précédente.
     *
     * Les achats immédiats (pack, objet, badge) passent aussi par lui
     * (26.10.2026) : deux clics ne font pas deux prélèvements.
     *
     * @param  callable(): ?Reglement  $action
     * @param  array{0: string, 1: string}  $occupe  champ et message quand le verrou est pris
     */
    /**
     * Le verrou de l'abonné, pris sans attendre : déjà pris (autre passage,
     * achat en cours), l'abonné est laissé, le passage suivant le reprend.
     */
    private function siLibre(Organisation|User $abonne, callable $action): void
    {
        $verrou = Cache::lock('abonnement:'.($abonne instanceof Organisation ? 'organisation' : 'proprietaire').':'.$abonne->id, 600);
        if (! $verrou->get()) {
            return;
        }

        try {
            $abonne->refresh();
            $action();
        } finally {
            $verrou->release();
        }
    }

    private function sousVerrouAbonne(Organisation|User $abonne, callable $action, array $occupe = ['plan', 'Un paiement est déjà en cours. Patientez un instant.']): ?Reglement
    {
        // Englobe le verrou de la facture (300 s) : toujours plus long
        $verrou = Cache::lock('abonnement:'.($abonne instanceof Organisation ? 'organisation' : 'proprietaire').':'.$abonne->id, 600);
        if (! $verrou->get()) {
            throw ValidationException::withMessages([$occupe[0] => $occupe[1]]);
        }

        try {
            // Relu une fois le verrou pris, jamais avant
            $abonne->refresh();

            return $action();
        } finally {
            $verrou->release();
        }
    }

    /** @return Builder<OrganisationFacture>|Builder<FactureProprietaire> */
    private function factures(Organisation|User $abonne): Builder
    {
        return $abonne instanceof Organisation
            ? OrganisationFacture::query()->where('organisation_id', $abonne->id)
            : FactureProprietaire::query()->where('user_id', $abonne->id);
    }

    private function abonneDe(OrganisationFacture|FactureProprietaire $facture): Organisation|User
    {
        return $facture instanceof OrganisationFacture
            ? Organisation::query()->findOrFail($facture->organisation_id)
            : User::query()->findOrFail($facture->user_id);
    }

    private function client(Organisation|User $abonne, ?string $email): string
    {
        if ($abonne->stripe_client_id) {
            return $abonne->stripe_client_id;
        }

        $id = $abonne instanceof Organisation
            ? $this->stripe->creerClient($abonne->nom, $email, ['organisation_id' => (string) $abonne->id])
            : $this->stripe->creerClient($abonne->name, $abonne->email, ['user_id' => (string) $abonne->id]);
        $abonne->forceFill(['stripe_client_id' => $id])->save();

        return $id;
    }

    private function detacher(string $moyen): void
    {
        try {
            $this->stripe->detacherMoyenPaiement($moyen);
        } catch (ErreurPaiement) {
            // Déjà détachée ou supprimée chez Stripe : rien à faire
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
