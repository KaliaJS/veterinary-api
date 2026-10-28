<?php

namespace App\Services\Comptes;

use App\Auth\CompteRevueApple;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Document;
use App\Models\Employe;
use App\Models\FactureProprietaire;
use App\Models\MessageClientele;
use App\Models\Patient;
use App\Models\User;
use App\Notifications\CodeSuppressionCompte;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleAbonnement;
use App\Paiements\PasserelleIdentite;
use App\Services\Abonnements\AbonnementSaas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Suppression du compte par son titulaire (App Store, règle 5.1.1(v) ;
 * décisions de Jim, 27.10.2026). Confirmée par un code envoyé par e-mail
 * (code fixe pour l'adresse de revue d'Apple), la demande ferme toutes les
 * sessions et résilie Plus sans remboursement ; le compte est effacé
 * 30 jours plus tard, par la tâche de nuit, sauf si son titulaire se
 * reconnecte entre-temps. La clinique garde ce qu'elle tient (fiche,
 * dossiers, factures, demandes, messages), détaché du compte ; les animaux
 * que le titulaire gérait seul partent avec lui. Un membre actif de
 * l'équipe d'une clinique ne se supprime pas ainsi ; un ancien membre
 * devient une trace qui ne garde que son prénom.
 */
class SuppressionCompte
{
    public const int DELAI_JOURS = 30;

    /** Heure de la tâche de nuit qui efface (routes/console.php) */
    public const string HEURE_EFFACEMENT = '03:45';

    private const int VALIDITE_MINUTES = 10;

    private const int ESSAIS_MAX = 5;

    /** Titres sautés pour garder le prénom d'une trace */
    private const array TITRES = [
        'dr', 'dre', 'docteur', 'docteure', 'pr', 'pre', 'prof', 'professeur', 'professeure', 'pd',
        'med', 'méd', 'vet', 'vét', 'dmv', 'dvm', 'phd', 'dipl', 'm', 'mme', 'mlle', 'me', 'mr', 'mrs', 'ms',
        // Tessin (Dott.ssa, Dr.ssa, Prof.ssa, Dottoressa), Suisse alémanique
        // (Tierärztin, Frau, Herr), Europe centrale (MVDr.) ; honoris causa à part
        'dott', 'ssa', 'dottore', 'dottoressa', 'tierarzt', 'tierärztin', 'tierarztin', 'mvdr',
        'frau', 'herr', 'madame', 'monsieur', 'signora', 'signor',
        // Suisse romande (Dresse, Drsse, Doctoresse), Autriche (Mag.), honoris causa (h. c.)
        'dresse', 'drsse', 'doctoresse', 'mag', 'hc',
    ];

    public function __construct(
        private CompteRevueApple $revue,
        private AbonnementSaas $abonnements,
        private PasserelleAbonnement $stripe,
        private PasserelleIdentite $identite,
    ) {}

    /** Un code neuf, cinq essais neufs ; aucun e-mail pour l'adresse de revue. Rend vrai si un e-mail est parti. */
    public function envoyerCode(User $user): bool
    {
        $this->exigerHorsEquipe($user);

        $codeRevue = $this->revue->codePour($user->email);
        $code = $codeRevue ?? (string) random_int(10000000, 99999999);

        Cache::put($this->cle($user), Hash::make($code), now()->addMinutes(self::VALIDITE_MINUTES));
        RateLimiter::clear($this->limiteur($user));

        if ($codeRevue !== null) {
            return false;
        }
        $user->notify(new CodeSuppressionCompte($code, self::VALIDITE_MINUTES));

        return true;
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
     * panne, rien n'est effacé et le compte attend la nuit suivante. Rend
     * vrai si le compte est effacé.
     */
    public function effacer(User $user): bool
    {
        // Relue : reconnecté entre-temps, la demande est tombée et rien ne se passe
        $user = $this->aEffacer($user->id);
        if ($user === null) {
            return false;
        }

        // Entré dans une équipe entre-temps (sans se reconnecter, en principe
        // impossible) : la base refuserait, la demande tombe
        if ($this->estMembreActif($user)) {
            $this->annuler($user);

            return false;
        }

        // La vérification d'identité (pièce, selfie) effacée chez Stripe ; en
        // panne passagère ou clé refusée, le compte attend la nuit suivante
        // (la pièce n'est jamais abandonnée chez Stripe). Une session
        // introuvable chez Stripe est signalée. Une session annulée s'efface
        // aussi (essayé en mode test le 27.10.2026)
        if ($user->identite_session_id) {
            try {
                $this->identite->effacerSession($user->identite_session_id);
            } catch (ErreurPaiement $e) {
                if ($e->estPassagere() || $e->cleRefusee()) {
                    throw $e;
                }
                report($e);
            }
        }

        // Une facture Plus encore en attente chez Stripe : son prélèvement est
        // annulé d'abord (en panne ou clé refusée, le compte attend la nuit
        // suivante). Un prélèvement inconnu du compte Stripe (ancien compte,
        // passage du mode test au réel) n'a rien à annuler : signalé
        $enAttente = FactureProprietaire::query()->where('user_id', $user->id)->where('statut', 'a_payer')->get();
        foreach ($enAttente as $facture) {
            if ($facture->stripe_paiement_id) {
                try {
                    $this->stripe->annulerPrelevement($facture->stripe_paiement_id);
                } catch (ErreurPaiement $e) {
                    if ($e->estPassagere() || $e->cleRefusee()) {
                        throw $e;
                    }
                    report($e);
                }
            }
        }

        // Le client supprimé chez Stripe emporte ses cartes : un seul appel,
        // qu'une panne laisse à refaire tel quel la nuit suivante
        $clientSupprime = false;
        if ($user->stripe_client_id) {
            $this->stripe->supprimerClient($user->stripe_client_id);
            $clientSupprime = true;
        }

        $fichiers = DB::transaction(function () use ($user): ?array {
            // Relue sous verrou : une connexion à cet instant gagne
            if (User::query()->whereKey($user->id)->lockForUpdate()->value('suppression_demandee_le') === null) {
                return null;
            }
            $fichiers = [];

            // Les factures en attente ne se paieront plus
            FactureProprietaire::query()->where('user_id', $user->id)->where('statut', 'a_payer')->get()
                ->each(fn (FactureProprietaire $facture) => $facture->forceFill([
                    'statut' => 'annulee',
                    'metadonnees' => collect($facture->metadonnees ?? [])->except('demande_en_suspens')->all(),
                ])->save());

            // Pièces comptables de Meteora (factures Plus, CO 958f) : gardées,
            // détachées par la base, avec le nom et l'adresse du titulaire
            FactureProprietaire::query()->where('user_id', $user->id)
                ->update(['titulaire_nom' => $user->name, 'titulaire_email' => $user->email]);

            // Le fil gardé par la clinique reste attribuable : sa fiche client
            $fils = MessageClientele::query()->where('user_id', $user->id)->distinct()->pluck('clinique_id');
            foreach ($fils as $cliniqueId) {
                $organisation = Clinique::withoutGlobalScopes()->with('societe')->find($cliniqueId)?->societe?->organisation_id;
                $fiche = $organisation ? Client::withoutGlobalScopes()->where('user_id', $user->id)->where('organisation_id', $organisation)->value('id') : null;
                if ($fiche) {
                    MessageClientele::query()->where('user_id', $user->id)->where('clinique_id', $cliniqueId)->update(['client_id' => $fiche]);
                }
            }

            // Les animaux qu'il gérait seul, sans clinique ; ceux d'une
            // clinique restent à la clinique (proprietaire_id vidé par la base)
            $seuls = Patient::withoutGlobalScopes()->where('proprietaire_id', $user->id)->whereNull('organisation_id')->get();
            foreach ($seuls as $animal) {
                $fichiers[] = $animal->photo_chemin;
                array_push($fichiers, ...Document::withoutGlobalScopes()->where('patient_id', $animal->id)->pluck('chemin')->all());
                $animal->delete();
            }

            // Ancien membre d'équipe : une trace sans connexion, prénom seul
            // (décision de Jim, 27.10.2026), à laquelle restent liées sa fiche
            // d'équipe et chaque pièce qu'il a signée. Sinon, le compte part
            // et la base détache ce que la clinique garde.
            if (Employe::withoutGlobalScopes()->where('user_id', $user->id)->exists()) {
                $this->fermerEnTrace($user);
            } else {
                $user->tokens()->delete();
                $user->delete();
            }

            return array_values(array_filter($fichiers, fn ($chemin) => is_string($chemin) && $chemin !== ''));
        });

        if ($fichiers === null) {
            // Reconnecté pendant l'effacement : le compte reste, mais son client
            // Stripe n'existe plus ; il ne garde pas un lien mort (une carte
            // s'enregistrera sur un client neuf)
            if ($clientSupprime) {
                User::query()->whereKey($user->id)->update([
                    'stripe_client_id' => null, 'stripe_moyen_paiement_id' => null, 'moyen_paiement' => null, 'carte_expire_le' => null,
                ]);
            }

            return false;
        }
        Storage::disk('prive')->delete($fichiers);

        return true;
    }

    /**
     * Le compte d'un ancien membre d'équipe devient une trace : plus
     * d'adresse ni de connexion, plus rien de personnel que son prénom, qui
     * signe encore ses pièces. Ce qu'il tenait comme propriétaire est
     * détaché ou effacé comme pour un compte effacé.
     */
    private function fermerEnTrace(User $user): void
    {
        foreach ([
            'clients' => 'user_id', 'patients' => 'proprietaire_id', 'demandes_rendez_vous' => 'user_id',
            'messages_clientele' => 'user_id', 'demandes_renouvellement_ordonnance' => 'user_id',
            'proprietaire_factures' => 'user_id', 'revocations_app_store' => 'user_id',
        ] as $table => $colonne) {
            DB::table($table)->where($colonne, $user->id)->update([$colonne => null]);
        }
        foreach (['passkeys', 'preferences_proprietaire', 'achats_planete', 'demandes_rattachement', 'specialites_veterinaire', 'parcours_veterinaire', 'decisions_verification'] as $table) {
            DB::table($table)->where('user_id', $user->id)->delete();
        }
        $user->tokens()->delete();

        // Le prénom, sans les titres devant, seuls ou en suite (Dre, Prof.,
        // PD Dr., Dr. med. vet., Dr.med.vet.)
        // Découpé sur le point et le trait d'union (Dr.med.vet., Dipl.-Tierärztin) ;
        // une initiale (« C. », « H.C. ») n'est pas un titre
        $estTitre = function (string $mot): bool {
            $mot = mb_strtolower(rtrim($mot, '.'));

            return collect(preg_split('/[.\-]/u', $mot) ?: [])->filter(fn (string $morceau): bool => $morceau !== '')
                ->every(fn (string $morceau): bool => in_array($morceau, self::TITRES, true));
        };
        $nom = trim((string) $user->name);
        $nom = (string) (\Normalizer::normalize($nom, \Normalizer::FORM_C) ?: $nom);
        // Honoris causa, en minuscules seulement (« h. c. », « Dr.h.c. ») : un seul titre
        $nom = (string) preg_replace('/(?<!\p{L})h\.\s*c\.?(?!\p{L})/u', ' hc ', $nom);
        $prenom = collect(preg_split('/\s+/u', $nom) ?: [])
            ->first(fn (string $mot): bool => $mot !== '' && ! $estTitre($mot));
        $user->forceFill([
            'efface_le' => now(),
            'name' => $prenom ?? 'Ancien membre',
            'email' => 'compte-efface-'.$user->id.'@veterinary.invalid',
            'password' => Hash::make(Str::random(64)),
            'email_verified_at' => null,
            'suppression_demandee_le' => null,
            'gln' => null, 'medreg_id' => null, 'gln_verifie_le' => null,
            'verification_veterinaire' => null, 'verification_veterinaire_le' => null,
            'identite_statut' => null, 'identite_session_id' => null, 'identite_nom' => null, 'identite_raison' => null,
            'identite_verifiee_le' => null, 'identite_payee_le' => null,
            'est_moderateur' => false, 'presentation' => null, 'langues' => null, 'annuaire_accord_le' => null, 'code_employe' => null,
            'formule' => 'gratuit', 'apple_compte' => null, 'apple_abonnement_id' => null, 'apple_fin' => null, 'apple_renouvelle' => null, 'apple_signe_le' => null,
            'stripe_client_id' => null, 'stripe_moyen_paiement_id' => null, 'moyen_paiement' => null, 'carte_expire_le' => null, 'carte_alertee_le' => null,
            'statut_abonnement' => null, 'periode_fin' => null, 'periode_debut' => null, 'ancre_jour' => null, 'tentatives' => 0,
            'prochaine_tentative_le' => null, 'resilie_le' => null,
        ])->save();
    }

    /** Membre d'une équipe dont l'accès n'est pas terminé (un ancien membre peut supprimer son compte). */
    private function estMembreActif(User $user): bool
    {
        // Comme à la porte de la clinique : ni suspendu, ni contrat terminé à
        // la date de la clinique (son fuseau, pas celui de Zurich)
        return Employe::withoutGlobalScopes()->with('cliniques')->where('user_id', $user->id)->where('acces_suspendu', false)->get()
            ->contains(fn (Employe $fiche): bool => $fiche->fin_contrat_le === null
                || ($fiche->cliniques->isEmpty()
                    ? ! $fiche->fin_contrat_le->lt(aujourdhui())
                    : $fiche->cliniques->contains(fn (Clinique $clinique): bool => ! $fiche->fin_contrat_le->lt(aujourdhui($clinique)))));
    }

    /** Le compte, si sa demande a toujours ses 30 jours (relu en base). */
    private function aEffacer(int $id): ?User
    {
        return User::query()->whereKey($id)
            ->whereNotNull('suppression_demandee_le')
            ->where('suppression_demandee_le', '<=', now()->subDays(self::DELAI_JOURS))
            ->first();
    }

    /** @throws ValidationException */
    private function exigerHorsEquipe(User $user): void
    {
        if ($this->estMembreActif($user)) {
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
