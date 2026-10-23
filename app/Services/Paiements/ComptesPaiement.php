<?php

namespace App\Services\Paiements;

use App\Enums\TypeAlerte;
use App\Mail\InformationsPaiementDemandees;
use App\Models\Clinique;
use App\Models\Societe;
use App\Paiements\PasserellePaiement;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Comptes de paiement des sociétés (Stripe Connect, 23.10.2026) : chaque
 * société encaisse à son nom (terminal, TWINT, acomptes) sur un compte
 * sans tableau de bord. Elle ne voit jamais le prestataire : ni compte à
 * créer chez lui, ni connexion, ni message de sa part ; l'inscription, la
 * gestion du compte, les paiements et les virements sont des composants
 * intégrés à Veterinary, à ses couleurs.
 *
 * Paiements invisibles (décision du 23.10.2026) : la plateforme porte les
 * pertes (litiges, soldes négatifs), collecte les informations légales et
 * paie les frais du prestataire, qu'elle refacture à chaque encaissement
 * avec sa marge (`commission`). C'est aussi elle qui prévient la société
 * quand des informations sont demandées (alerte et e-mail).
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

        if (! $societe?->stripe_compte_id || ($pourEncaisser && ! $societe->paiements_actifs)) {
            throw ValidationException::withMessages(['paiements' => 'Les paiements par carte et TWINT ne sont pas encore activés pour '
                .($societe?->nom ?? 'cette clinique').'. Un administrateur les active dans Réglages, Paiements.']);
        }

        return $this->passerelle->pourCompte($societe->stripe_compte_id);
    }

    /**
     * Tarif facturé à la société par moyen (terminal, twint, en_ligne) :
     * celui du prestataire plus la marge de la plateforme.
     *
     * @return array{taux: float, fixe: int}
     */
    public static function tarif(string $moyen): array
    {
        $tarif = config('services.stripe.tarifs.'.$moyen) ?? throw new \InvalidArgumentException('Moyen de paiement inconnu : '.$moyen);

        return ['taux' => round((float) $tarif['taux'] + (float) config('services.stripe.marge'), 2), 'fixe' => (int) $tarif['fixe']];
    }

    /** Commission de la plateforme sur un encaissement (centimes), toujours inférieure au montant. */
    public static function commission(string $moyen, int $centimes): int
    {
        ['taux' => $taux, 'fixe' => $fixe] = self::tarif($moyen);

        return max(0, min($centimes - 1, (int) round($centimes * $taux / 100) + $fixe));
    }

    /**
     * Ouvre le compte de la société s'il n'existe pas encore, avec
     * l'acceptation des conditions du prestataire par l'administrateur.
     *
     * @param  array{date: \DateTimeInterface, ip: string, navigateur: ?string}  $acceptation
     */
    public function ouvrir(Societe $societe, ?string $email, array $acceptation): Societe
    {
        if (! $societe->stripe_compte_id) {
            $id = $this->passerelle->pourCompte(null)->creerComptePaiement([
                'nom' => $societe->nom,
                'email' => $email,
                'conditions' => $acceptation,
                'metadonnees' => ['societe_id' => (string) $societe->id, 'organisation_id' => (string) $societe->organisation_id],
            ]);
            $societe->update(['stripe_compte_id' => $id]);
        }

        return $this->rafraichir($societe);
    }

    /**
     * Relit l'état du compte : encaisse-t-il, reçoit-il ses virements,
     * manque-t-il des informations. Le prestataire ne contactant jamais la
     * société, les administrateurs sont prévenus par e-mail quand des
     * informations deviennent nécessaires sur un compte déjà en service.
     */
    public function rafraichir(Societe $societe): Societe
    {
        if (! $societe->stripe_compte_id) {
            return $societe;
        }

        $etat = $this->passerelle->pourCompte(null)->lireComptePaiement($societe->stripe_compte_id);
        $avant = ['paiements' => (bool) $societe->paiements_actifs, 'a_completer' => (bool) $societe->paiements_a_completer];
        $societe->update([
            'paiements_actifs' => $etat['paiements'],
            'virements_actifs' => $etat['virements'],
            'paiements_a_completer' => $etat['a_completer'],
            'paiements_maj_le' => now(),
        ]);

        $enService = $avant['paiements'] || $etat['paiements'];
        if ($enService && $etat['a_completer'] && ! $avant['a_completer']) {
            $destinataires = $societe->organisation?->emailsAdministrateurs(TypeAlerte::Paiements) ?? [];
            if ($destinataires !== []) {
                Mail::to($destinataires)->queue(new InformationsPaiementDemandees($societe, coupe: ! $etat['paiements']));
            }
        }

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
    public function session(Societe $societe, array $composants): string
    {
        if (! $societe->stripe_compte_id) {
            throw ValidationException::withMessages(['composants' => 'Le compte de paiement n’est pas encore ouvert.']);
        }

        return $this->passerelle->pourCompte(null)->creerSessionCompte($societe->stripe_compte_id, array_values($composants));
    }
}
