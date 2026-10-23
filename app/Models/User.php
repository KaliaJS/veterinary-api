<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\DecisionVerification as Decision;
use App\Enums\StatutIdentite;
use App\Enums\StatutVerificationVeterinaire;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * retiré de l'autre compte quand quelqu'un le prend (23.10.2026).
     */
    public static function libererGlnNonVerifie(string $gln, self $preneur): void
    {
        self::query()->where('gln', $gln)->whereKeyNot($preneur->id)->whereNull('gln_verifie_le')
            ->update(['gln' => null, 'medreg_id' => null]);
    }

    /** Un code neuf (Réglages, Profil) : l'ancien ne retrouve plus le compte. */
    public function nouveauCodeEmploye(): string
    {
        do {
            $code = collect(range(1, 6))->map(fn (): string => self::ALPHABET_CODE[random_int(0, strlen(self::ALPHABET_CODE) - 1)])->implode('');
        } while (self::query()->where('code_employe', $code)->exists());
        $this->forceFill(['code_employe' => $code])->save();

        return $code;
    }

    /** Le code tel qu'on le lit et le dicte : « K7F-3QZ ». */
    public static function formaterCodeEmploye(string $code): string
    {
        return substr($code, 0, 3).'-'.substr($code, 3);
    }

    /** Code saisi (« k7f 3qz », « K7F-3QZ ») ramené à sa forme stockée, ou null. */
    public static function normaliserCodeEmploye(string $saisi): ?string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $saisi));

        return strlen($code) === 6 ? $code : null;
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
