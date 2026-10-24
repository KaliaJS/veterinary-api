<?php

namespace App\Models;

use App\Enums\DecisionVerification as Decision;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\StatutIdentite;
use App\Enums\StatutVerificationVeterinaire;
use App\Mail\GlnRepris;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** Miroir des défauts de la base, connus dès la création (sans relire la ligne) */
    protected $attributes = [
        'est_moderateur' => false,
    ];

    private const ALPHABET_CODE = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'periode_debut' => 'date',
            'periode_fin' => 'date',
            'resilie_le' => 'date',
            'prochaine_tentative_le' => 'datetime',
            'carte_expire_le' => 'date',
            'carte_alertee_le' => 'date',
            'apple_fin' => 'datetime',
            'apple_signe_le' => 'datetime',
            'apple_renouvelle' => 'boolean',
            'langues' => 'array',
            'annuaire_accord_le' => 'datetime',
            'gln_verifie_le' => 'datetime',
            'verification_veterinaire' => StatutVerificationVeterinaire::class,
            'verification_veterinaire_le' => 'datetime',
            'identite_statut' => StatutIdentite::class,
            'identite_verifiee_le' => 'datetime',
            'identite_payee_le' => 'datetime',
            'est_moderateur' => 'boolean',
        ];
    }

    /** Vétérinaire vérifié à la main par la plateforme (badge « Vérifié manuellement »). */
    public function estVerifie(): bool
    {
        return $this->verification_veterinaire === StatutVerificationVeterinaire::Verifie;
    }

    /** Vérifié et d'accord pour paraître nommément dans l'annuaire (nLPD, 23.10.2026). */
    public function estNommableDansLAnnuaire(): bool
    {
        return $this->estVerifie() && $this->annuaire_accord_le !== null;
    }

    /**
     * Demande ou décision de vérification : le statut du compte change
     * et l'historique garde qui, quand et pourquoi.
     */
    public function consignerVerification(Decision $decision, ?StatutVerificationVeterinaire $statut, ?string $note = null, ?User $moderateur = null): void
    {
        DB::transaction(function () use ($decision, $statut, $note, $moderateur): void {
            $this->forceFill(['verification_veterinaire' => $statut, 'verification_veterinaire_le' => now()])->save();
            $this->decisionsVerification()->create(['decision' => $decision, 'note' => $note, 'moderateur_id' => $moderateur?->id]);
        });
    }

    /** @return HasMany<DecisionVerification, $this> */
    public function decisionsVerification(): HasMany
    {
        return $this->hasMany(DecisionVerification::class)->latest()->latest('id');
    }

    /** Plus payé par Stripe (site) : abonnement en cours, même résilié d'ici la fin du mois. */
    public function aPlusStripe(): bool
    {
        return $this->formule === 'plus';
    }

    /** Plus payé par l'App Store (iPhone) : jusqu'à la fin de la période achetée. */
    public function aPlusApple(): bool
    {
        return $this->apple_fin !== null && $this->apple_fin->isFuture();
    }

    public function aPlus(): bool
    {
        return $this->aPlusStripe() || $this->aPlusApple();
    }

    /** Factures de l'offre Plus (propriétaire).
     * @return HasMany<FactureProprietaire, $this> */
    public function facturesPlus(): HasMany
    {
        return $this->hasMany(FactureProprietaire::class);
    }

    /**
     * Rattachements clinique de l'utilisateur — un par clinique où il
     * est employé. Le jeton d'API est émis pour l'un d'entre eux.
     *
     * @return HasMany<Employe, $this>
     */
    public function employes(): HasMany
    {
        return $this->hasMany(Employe::class);
    }

    /**
     * Code court que la personne donne à une organisation pour s'y faire
     * embaucher (Réglages, Profil) : créé à la première demande, sans
     * caractères qui se confondent (0, O, 1, I, L).
     */
    public function codeEmploye(): string
    {
        if ($this->code_employe === null) {
            $this->nouveauCodeEmploye();
        }

        return $this->code_employe;
    }

    /**
     * Un GLN saisi sans vérification ailleurs ne réserve rien : il est
     * retiré de l'autre compte quand quelqu'un le prend (23.10.2026). Ce
     * compte est prévenu par e-mail, après l'enregistrement (24.10.2026, décidé
     * avec Jim) : si c'est le vrai titulaire, il fait vérifier son identité.
     */
    public static function libererGlnNonVerifie(string $gln, self $preneur): void
    {
        // Le nom concordant dans MedReg ne suffit plus (24.10.2026) : un GLN
        // et un nom sont publics, seule l'identité vérifiée réserve le GLN.
        // Lus sous verrou : une identité vérifiée entre la lecture et la mise
        // à jour garde son GLN
        $perdants = DB::transaction(function () use ($gln, $preneur) {
            $perdants = self::query()->where('gln', $gln)->whereKeyNot($preneur->id)
                ->where(fn ($requete) => self::sansIdentiteEtablie($requete))
                ->lockForUpdate()
                ->get(['id', 'email']);
            if ($perdants->isNotEmpty()) {
                self::query()->whereKey($perdants->modelKeys())->update(['gln' => null, 'medreg_id' => null, 'gln_verifie_le' => null]);
            }

            return $perdants;
        });
        $verification = config('plateforme.verification_email');
        foreach ($perdants as $perdant) {
            $envoi = Mail::to($perdant->email);
            if ($verification) {
                $envoi->bcc($verification);
            }
            $envoi->queue((new GlnRepris($gln))->afterCommit());
        }
    }

    /**
     * Identité établie : pièce vérifiée par Stripe Identity, ou vérification
     * faite à la main par un modérateur. Elle seule réserve un GLN.
     */
    public function identiteEtablie(): bool
    {
        return $this->identite_verifiee_le !== null || $this->verification_veterinaire === StatutVerificationVeterinaire::Verifie;
    }

    /**
     * @param  Builder<self>  $requete
     * @return Builder<self>
     */
    public static function avecIdentiteEtablie(Builder $requete): Builder
    {
        return $requete->where(fn ($q) => $q->whereNotNull('identite_verifiee_le')->orWhere('verification_veterinaire', StatutVerificationVeterinaire::Verifie->value));
    }

    /**
     * @param  Builder<self>  $requete
     * @return Builder<self>
     */
    public static function sansIdentiteEtablie(Builder $requete): Builder
    {
        return $requete->whereNull('identite_verifiee_le')
            ->where(fn ($q) => $q->whereNull('verification_veterinaire')->orWhere('verification_veterinaire', '!=', StatutVerificationVeterinaire::Verifie->value));
    }

    /** Un code neuf (Réglages, Profil) : l'ancien ne retrouve plus le compte. */
    public function nouveauCodeEmploye(): string
    {
        do {
            $code = collect(range(1, self::LONGUEUR_CODE))->map(fn (): string => self::ALPHABET_CODE[random_int(0, strlen(self::ALPHABET_CODE) - 1)])->implode('');
        } while (self::query()->where('code_employe', $code)->exists());
        $this->forceFill(['code_employe' => $code])->save();

        return $code;
    }

    /** Huit caractères depuis le 24.10.2026 (six avant) : trop long pour se deviner */
    public const int LONGUEUR_CODE = 8;

    /** Le code tel qu'on le lit et le dicte : « K7F3-QZ8M ». */
    public static function formaterCodeEmploye(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /** Code saisi (« k7f3 qz8m », « K7F3-QZ8M ») ramené à sa forme stockée, ou null. */
    public static function normaliserCodeEmploye(string $saisi): ?string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $saisi));

        return strlen($code) === self::LONGUEUR_CODE ? $code : null;
    }

    /** @return HasMany<Passkey, $this> */
    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    /** Prénom dérivé de `name` — contrat du front (`user.first`). */
    public function prenom(): string
    {
        return Str::before($this->name, ' ');
    }

    /** Nom dérivé de `name` — contrat du front (`user.last`). */
    public function nomFamille(): string
    {
        return Str::contains($this->name, ' ') ? Str::after($this->name, ' ') : '';
    }

    /**
     * Spécialités déclarées par le vétérinaire lui-même (Réglages,
     * Profil) : elles le suivent dans toutes ses cliniques.
     *
     * @return HasMany<SpecialiteVeterinaire, $this>
     */
    public function specialites(): HasMany
    {
        return $this->hasMany(SpecialiteVeterinaire::class);
    }

    /**
     * Parcours déclaré par le vétérinaire (postes et formations), dans
     * l'ordre où il les a rangés.
     *
     * @return HasMany<ParcoursVeterinaire, $this>
     */
    public function parcours(): HasMany
    {
        return $this->hasMany(ParcoursVeterinaire::class)->orderBy('ordre')->orderBy('id');
    }

    /**
     * Ses fiches employé de vétérinaire, actives, dans des cliniques de
     * l'annuaire qui montrent leurs vétérinaires : ce que la fiche
     * publique peut montrer.
     *
     * @return Collection<int, Employe>
     */
    public function postesVisibles(): Collection
    {
        return Employe::withoutGlobalScopes()
            ->where('user_id', $this->id)
            ->with(['organisation', 'cliniques' => fn ($cliniques) => $cliniques->withoutGlobalScopes()->ouvertes()->where('affiche_veterinaires', true)->with('societe')])
            ->get()
            ->filter(fn (Employe $fiche): bool => $fiche->estVeterinaire() && $fiche->estPresentable())
            // Les cliniques de l'organisation de la fiche seulement (défense en profondeur)
            ->each(fn (Employe $fiche) => $fiche->setRelation('cliniques', $fiche->cliniques->filter(fn (Clinique $clinique): bool => $clinique->estDansLAnnuaire() && $clinique->societe?->organisation_id === $fiche->organisation_id)->values()))
            ->filter(fn (Employe $fiche): bool => $fiche->cliniques->isNotEmpty())
            ->values();
    }
}
