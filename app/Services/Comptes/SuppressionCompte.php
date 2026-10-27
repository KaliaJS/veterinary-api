<?php

namespace App\Services\Comptes;

use App\Auth\CompteRevueApple;
use App\Models\Document;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\User;
use App\Notifications\CodeSuppressionCompte;
use App\Paiements\PasserelleAbonnement;
use App\Services\Abonnements\AbonnementSaas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Suppression du compte par son titulaire (App Store, règle 5.1.1(v) ;
 * décisions de Jim, 27.10.2026). Confirmée par un code envoyé par e-mail
 * (code fixe pour l'adresse de revue d'Apple), la demande ferme toutes les
 * sessions et résilie Plus sans remboursement ; le compte est effacé
 * 30 jours plus tard, par la tâche de nuit, sauf si son titulaire se
 * reconnecte entre-temps. La clinique garde ce qu'elle tient (fiche,
 * dossiers, factures, demandes, messages), détaché du compte ; les animaux
 * que le titulaire gérait seul partent avec lui. Un compte de l'équipe
 * d'une clinique ne se supprime pas ainsi.
 */
class SuppressionCompte
{
    public const int DELAI_JOURS = 30;

    /** Heure de la tâche de nuit qui efface (routes/console.php) */
    public const string HEURE_EFFACEMENT = '03:45';

    private const int VALIDITE_MINUTES = 10;

    private const int ESSAIS_MAX = 5;

    public function __construct(
        private CompteRevueApple $revue,
        private AbonnementSaas $abonnements,
        private PasserelleAbonnement $stripe,
    ) {}

    /** Un code neuf, cinq essais neufs ; aucun e-mail pour l'adresse de revue. */
    public function envoyerCode(User $user): void
    {
        $this->exigerHorsEquipe($user);

        $codeRevue = $this->revue->codePour($user->email);
        $code = $codeRevue ?? (string) random_int(10000000, 99999999);

        Cache::put($this->cle($user), Hash::make($code), now()->addMinutes(self::VALIDITE_MINUTES));
        RateLimiter::clear($this->limiteur($user));

        if ($codeRevue === null) {
            $user->notify(new CodeSuppressionCompte($code, self::VALIDITE_MINUTES));
        }
    }

    /**
     * Le code juste : Plus résilié, sessions fermées, effacement dans
     * 30 jours. Rend le jour de l'effacement.
     *
     * @throws ValidationException
     */
    public function demander(User $user, string $code): CarbonImmutable
    {
        $this->exigerHorsEquipe($user);

        // L'essai est compté avant la vérification, comme pour la connexion
        $essais = RateLimiter::increment($this->limiteur($user), self::VALIDITE_MINUTES * 60);
        if ($essais > self::ESSAIS_MAX) {
            Cache::forget($this->cle($user));

            throw ValidationException::withMessages(['code' => 'Trop de tentatives. Demandez un nouveau code.']);
        }

        $hache = Cache::get($this->cle($user));
        if (! $hache || ! Hash::check($code, $hache)) {
            if ($essais >= self::ESSAIS_MAX) {
                Cache::forget($this->cle($user));
            }

            throw ValidationException::withMessages(['code' => 'Code invalide ou expiré.']);
        }

        // Plus ne se renouvelle plus ; ce qui est payé n'est pas remboursé
        if (in_array($user->statut_abonnement, ['actif', 'impaye'], true) && $user->periode_fin !== null && $user->resilie_le === null) {
            $this->abonnements->resilier($user);
        }

        Cache::forget($this->cle($user));
        RateLimiter::clear($this->limiteur($user));

        $user->forceFill(['suppression_demandee_le' => now()])->save();
        // Toutes les sessions se ferment : se reconnecter annule la demande
        $user->tokens()->delete();

        return $this->effaceLe($user);
    }

    /** Reconnexion pendant les 30 jours : la demande tombe. Rend vrai s'il y en avait une. */
    public function annuler(User $user): bool
    {
        $annulee = User::query()->whereKey($user->id)->whereNotNull('suppression_demandee_le')
            ->update(['suppression_demandee_le' => null]) > 0;
        $user->suppression_demandee_le = null;

        return $annulee;
    }

    /** Jour où la tâche de nuit efface le compte : la première nuit après les 30 jours. */
    public function effaceLe(User $user): CarbonImmutable
    {
        $echeance = CarbonImmutable::parse($user->suppression_demandee_le)->addDays(self::DELAI_JOURS)->setTimezone('Europe/Zurich');
        $nuit = $echeance->setTimeFromTimeString(self::HEURE_EFFACEMENT);

        return ($echeance->lessThanOrEqualTo($nuit) ? $nuit : $nuit->addDay())->startOfDay();
    }

    /**
     * Efface un compte dont la demande a 30 jours. Stripe d'abord : en
     * panne, rien n'est effacé et le compte attend la nuit suivante.
     */
    public function effacer(User $user): void
    {
        // Entré dans une équipe entre-temps (sans se reconnecter, en principe
        // impossible) : la base refuserait, la demande tombe
        if (Employe::withoutGlobalScopes()->where('user_id', $user->id)->exists()) {
            $this->annuler($user);

            return;
        }

        if ($user->stripe_moyen_paiement_id) {
            $this->stripe->detacherMoyenPaiement($user->stripe_moyen_paiement_id);
        }
        if ($user->stripe_client_id) {
            $this->stripe->supprimerClient($user->stripe_client_id);
        }

        $fichiers = DB::transaction(function () use ($user): array {
            $fichiers = [];
            // Les animaux qu'il gérait seul, sans clinique ; ceux d'une
            // clinique restent à la clinique (proprietaire_id vidé par la base)
            $seuls = Patient::withoutGlobalScopes()->where('proprietaire_id', $user->id)->whereNull('organisation_id')->get();
            foreach ($seuls as $animal) {
                $fichiers[] = $animal->photo_chemin;
                array_push($fichiers, ...Document::withoutGlobalScopes()->where('patient_id', $animal->id)->pluck('chemin')->all());
                $animal->delete();
            }

            $user->tokens()->delete();
            $user->delete();

            return array_values(array_filter($fichiers, fn ($chemin) => is_string($chemin) && $chemin !== ''));
        });

        Storage::disk('prive')->delete($fichiers);
    }

    /** @throws ValidationException */
    private function exigerHorsEquipe(User $user): void
    {
        if (Employe::withoutGlobalScopes()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'compte' => 'Votre compte fait partie de l’équipe d’une clinique. Quittez d’abord l’équipe, ou demandez à son administrateur de vous en retirer, puis supprimez votre compte.',
            ]);
        }
    }

    private function cle(User $user): string
    {
        return 'suppression-compte:'.$user->id;
    }

    private function limiteur(User $user): string
    {
        return 'suppression-compte-essais:'.$user->id;
    }
}
