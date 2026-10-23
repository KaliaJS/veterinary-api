<?php

namespace App\Services\Paiements;

use App\Models\Clinique;
use App\Models\Societe;
use App\Paiements\PasserellePaiement;
use App\Rules\NumeroIde;
use Illuminate\Validation\ValidationException;

/**
 * Comptes de paiement des sociétés (Stripe Connect, 23.10.2026) : chaque
 * société encaisse à son nom (terminal, TWINT, acomptes) sur un compte
 * sans tableau de bord. Elle ne voit jamais le prestataire : l'inscription,
 * la gestion du compte, les paiements et les virements sont des composants
 * intégrés à Veterinary. Le prestataire collecte les informations légales,
 * prélève ses frais auprès de la société et porte les pertes (impayés,
 * litiges) ; la plateforme prend sa commission sur chaque transaction
 * (terminal, TWINT à la caisse, acomptes en ligne).
 */
class ComptesPaiement
{
    /** Composants intégrés : ceux qui touchent le compte demandent le domaine organisation. */
    public const COMPOSANTS_ORGANISATION = ['account_onboarding', 'account_management'];

    public const COMPOSANTS_FACTURATION = ['notification_banner', 'payments', 'payouts', 'balances'];

    public function __construct(private PasserellePaiement $passerelle) {}

    /**
     * La passerelle sur le compte de la société de la clinique. Pour un
     * nouvel encaissement, le compte doit pouvoir encaisser ; pour suivre
     * ou rembourser un paiement passé, il suffit qu'il existe.
     */
    public function passerellePour(Clinique|int $clinique, bool $pourEncaisser = false): PasserellePaiement
    {
        $clinique = $clinique instanceof Clinique ? $clinique : Clinique::withoutGlobalScopes()->findOrFail($clinique);
        $societe = $clinique->societe;

        if ($pourEncaisser && ! $clinique->paiements_integres) {
            throw ValidationException::withMessages(['paiements' => $clinique->nom.' encaisse les cartes et TWINT sur son propre terminal : marquez le paiement comme payé.']);
        }

        if (! $societe?->stripe_compte_id || ($pourEncaisser && ! $societe->paiements_actifs)) {
            throw ValidationException::withMessages(['paiements' => 'Les paiements par carte et TWINT ne sont pas encore activés pour '
                .($societe?->nom ?? 'cette clinique').'. Un administrateur les active dans Réglages, Paiements.']);
        }

        return $this->passerelle->pourCompte($societe->stripe_compte_id);
    }

    /** Commission de la plateforme sur un paiement au terminal (centimes). */
    public static function commissionTerminal(int $centimes): int
    {
        return (int) round($centimes * (float) config('services.stripe.commission_terminal') / 100);
    }

    /** Et sur TWINT à la caisse. */
    public static function commissionTwint(int $centimes): int
    {
        return (int) round($centimes * (float) config('services.stripe.commission_twint') / 100);
    }

    /** Et sur un acompte payé en ligne (carte ou TWINT). */
    public static function commissionEnLigne(int $centimes): int
    {
        return (int) round($centimes * (float) config('services.stripe.commission_en_ligne') / 100);
    }

    /** Ouvre le compte de la société s'il n'existe pas encore. */
    /**
     * Ouvre le compte de la société s'il n'existe pas encore, prérempli
     * pour raccourcir l'inscription : nom commercial (celui de la clinique,
     * vu par les clients sur leur relevé et dans TWINT), IDE, adresse et
     * téléphone de la clinique.
     */
    public function ouvrir(Societe $societe, ?string $email, ?Clinique $clinique = null): Societe
    {
        if (! $societe->stripe_compte_id) {
            $id = $this->passerelle->pourCompte(null)->creerComptePaiement([
                'nom' => $societe->nom,
                'nom_commercial' => $clinique?->raison_sociale ?: $clinique?->nom ?: $societe->nom,
                'email' => $email,
                // Seulement un IDE au chiffre de contrôle juste
                'ide' => NumeroIde::valide($societe->ide) ? $societe->ide : null,
                'adresse' => $clinique?->rue ? ['rue' => $clinique->rue, 'npa' => $clinique->npa, 'ville' => $clinique->ville] : null,
                'telephone' => self::telephone($clinique?->tel),
                'metadonnees' => ['societe_id' => (string) $societe->id, 'organisation_id' => (string) $societe->organisation_id],
            ]);
            $societe->update(['stripe_compte_id' => $id]);
        }

        return $this->rafraichir($societe);
    }

    /** Numéro au format international (+41…), null s'il n'est pas lisible. */
    public static function telephone(?string $numero): ?string
    {
        $chiffres = preg_replace('/[^\d+]/', '', (string) $numero);
        if (str_starts_with($chiffres, '00')) {
            $chiffres = '+'.substr($chiffres, 2);
        } elseif (str_starts_with($chiffres, '0')) {
            $chiffres = '+41'.substr($chiffres, 1);
        }

        return preg_match('/^\+\d{8,15}$/', $chiffres) ? $chiffres : null;
    }

    /** Relit l'état du compte : encaisse-t-il, reçoit-il ses virements, manque-t-il des informations. */
    public function rafraichir(Societe $societe): Societe
    {
        if (! $societe->stripe_compte_id) {
            return $societe;
        }

        $etat = $this->passerelle->pourCompte(null)->lireComptePaiement($societe->stripe_compte_id);
        $societe->update([
            'paiements_actifs' => $etat['paiements'],
            'virements_actifs' => $etat['virements'],
            'paiements_a_completer' => $etat['a_completer'],
            'paiements_maj_le' => now(),
        ]);

        return $societe;
    }

    /** Événement `account.updated` d'un compte : son état est relu. */
    public function surCompteModifie(?string $compte): void
    {
        $societe = $compte ? Societe::query()->where('stripe_compte_id', $compte)->first() : null;

        if ($societe) {
            $this->rafraichir($societe);
        }
    }

    /**
     * Secret de session pour les composants intégrés demandés.
     *
     * @param  list<string>  $composants
     */
    public function session(Societe $societe, array $composants, bool $remboursements = false): string
    {
        if (! $societe->stripe_compte_id) {
            throw ValidationException::withMessages(['composants' => 'Le compte de paiement n’est pas encore ouvert.']);
        }

        return $this->passerelle->pourCompte(null)->creerSessionCompte($societe->stripe_compte_id, array_values($composants), $remboursements);
    }
}
