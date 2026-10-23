<?php

namespace App\Models;

use App\Auth\RolesParDefaut;
use App\Enums\DomainePermission;
use App\Enums\FormuleSaas;
use App\Enums\TypeAlerte;
use App\Enums\VerificationOrganisation;
use App\Services\PanelsLaboParDefaut;
use App\Services\PrestationsParDefaut;
use Carbon\CarbonImmutable;
use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory;

    protected $fillable = [
        'nom', 'plan', 'cycle', 'resilie_le', 'moyen_paiement',
        'usage_emails', 'usage_sms', 'usage_stockage_go',
        'quota_extra_emails', 'quota_extra_sms', 'quota_extra_stockage_go',
        'plafond_transcription', 'termes_transcription', 'plafond_diagnostic',
    ];

    /** Miroir des défauts de la base. */
    protected $attributes = [
        'plan' => 'cabinet',
        'verification' => 'en_attente',
        'cycle' => 'mois',
        'usage_emails' => 0,
        'usage_sms' => 0,
        'usage_stockage_go' => 0,
        'quota_extra_emails' => 0,
        'quota_extra_sms' => 0,
        'quota_extra_stockage_go' => 0,
        'plafond_transcription' => 20000,
        'plafond_diagnostic' => 5000,
    ];

    protected function casts(): array
    {
        return [
            'plan' => FormuleSaas::class,
            'verification' => VerificationOrganisation::class,
            'verifiee_le' => 'datetime',
            'identite_avant_le' => 'date',
            'resilie_le' => 'date',
            'periode_debut' => 'date',
            'periode_fin' => 'date',
            'prochaine_tentative_le' => 'datetime',
            'carte_expire_le' => 'date',
            'carte_alertee_le' => 'date',
            'usage_emails' => 'integer',
            'usage_sms' => 'integer',
            'usage_stockage_go' => 'decimal:1',
            'quota_extra_emails' => 'integer',
            'quota_extra_sms' => 'integer',
            'quota_extra_stockage_go' => 'integer',
            // Transcription des consultations : plafond mensuel en centimes HT,
            // mots rares à faire reconnaître (médicaments, races, marques)
            'plafond_transcription' => 'integer',
            'plafond_diagnostic' => 'integer',
            'termes_transcription' => 'array',
            'premiers_pas' => 'array',
        ];
    }

    /**
     * Les rôles par défaut, le catalogue de prestations et le
     * référentiel de panels labo naissent avec l'organisation (les
     * seeders, qui coupent les événements de modèle, les appellent
     * explicitement).
     */
    protected static function booted(): void
    {
        static::created(function (Organisation $organisation): void {
            RolesParDefaut::creer($organisation);
            PrestationsParDefaut::creer($organisation);
            PanelsLaboParDefaut::creer($organisation);
        });
    }

    /** Le compte qui a inscrit l'organisation (vérification, e-mails).
     * @return BelongsTo<User, $this> */
    public function inscritePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inscrite_par');
    }

    /**
     * Jours d'accès après l'échéance quand le renouvellement n'a pas encore
     * abouti (relances, banque qui traite, planificateur arrêté), jamais
     * au-delà (23.10.2026). Une résiliation ferme à l'échéance.
     */
    public const int GRACE_JOURS = 7;

    /** Une période payée court (un prélèvement refusé garde l'accès le temps des relances). */
    public function abonnementEnCours(): bool
    {
        if (! in_array($this->statut_abonnement, ['actif', 'impaye'], true) || $this->periode_fin === null) {
            return false;
        }

        $limite = $this->resilie_le ? $this->periode_fin : $this->periode_fin->copy()->addDays(self::GRACE_JOURS);

        return $limite->toDateString() >= CarbonImmutable::today('Europe/Zurich')->toDateString();
    }

    /**
     * Pourquoi l'organisation est fermée (application et annuaire), ou
     * null si elle est ouverte : vérifiée et payée.
     *
     * @return array{code: string, message: string}|null
     */
    public function fermeture(): ?array
    {
        return match (true) {
            $this->verification === VerificationOrganisation::EnAttente => [
                'code' => 'verification_en_attente',
                'message' => 'Nous vérifions encore votre clinique. Vous recevrez un e-mail dès que c’est fait.',
            ],
            $this->verification === VerificationOrganisation::Refusee => [
                'code' => 'verification_refusee',
                'message' => 'L’inscription de votre clinique a été refusée.',
            ],
            $this->identite_avant_le !== null && $this->identite_avant_le->toDateString() < CarbonImmutable::today('Europe/Zurich')->toDateString() => [
                'code' => 'identite_requise',
                'message' => 'Vérifiez votre identité pour rouvrir votre clinique.',
            ],
            ! $this->abonnementEnCours() => [
                'code' => 'abonnement_requis',
                'message' => 'Votre organisation n’a pas d’abonnement en cours. Choisissez une formule pour continuer.',
            ],
            default => null,
        };
    }

    /**
     * Ouverte avant la vérification d'identité de qui l'a inscrite
     * (23.10.2026) : ce qui coûte (transcription, IA) attend.
     */
    public function attendIdentite(): bool
    {
        return $this->identite_avant_le !== null;
    }

    public function estOuverte(): bool
    {
        return $this->fermeture() === null;
    }

    /** Organisations ouvertes : vérifiées et payées (annuaire, boutiques, sites).
     * @param  Builder<Organisation>  $requete */
    public function scopeOuvertes(Builder $requete): void
    {
        $aujourdhui = CarbonImmutable::today('Europe/Zurich');
        $requete->where('verification', VerificationOrganisation::Verifiee)
            ->where(fn (Builder $identite) => $identite->whereNull('identite_avant_le')->orWhereDate('identite_avant_le', '>=', CarbonImmutable::today('Europe/Zurich')->toDateString()))
            ->whereIn('statut_abonnement', ['actif', 'impaye'])
            ->where(fn (Builder $periode) => $periode
                ->where(fn (Builder $q) => $q->whereNull('resilie_le')->whereDate('periode_fin', '>=', $aujourdhui->subDays(self::GRACE_JOURS)->toDateString()))
                ->orWhere(fn (Builder $q) => $q->whereNotNull('resilie_le')->whereDate('periode_fin', '>=', $aujourdhui->toDateString())));
    }

    /** @return HasMany<Societe, $this> */
    public function societes(): HasMany
    {
        return $this->hasMany(Societe::class);
    }

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /** @return HasManyThrough<Clinique, Societe, $this> */
    public function cliniques(): HasManyThrough
    {
        return $this->hasManyThrough(Clinique::class, Societe::class);
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return HasMany<Patient, $this> */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /** Factures d'abonnement et de packs (« F- »).
     * @return HasMany<OrganisationFacture, $this> */
    public function facturesAbonnement(): HasMany
    {
        return $this->hasMany(OrganisationFacture::class);
    }

    /** @return HasMany<Employe, $this> */
    public function employes(): HasMany
    {
        return $this->hasMany(Employe::class);
    }

    /**
     * Employés ACTIFS (accès non suspendu) de l'organisation, chaque
     * personne une fois quel que soit le nombre de ses cliniques — la
     * grandeur plafonnée par la formule.
     */
    public function employesActifs(): int
    {
        return Employe::withoutGlobalScopes()
            ->where('organisation_id', $this->id)
            ->where('acces_suspendu', false)
            ->count();
    }

    /**
     * Limites dépassées pour une formule cible (overLims du front) —
     * vide si le changement est possible.
     *
     * @return list<string>
     */
    public function depassementsPour(FormuleSaas $cible): array
    {
        $limites = $cible->limites();
        $problemes = [];

        $cliniques = $this->cliniques()->count();
        if ($limites['cliniques'] !== null && $cliniques > $limites['cliniques']) {
            $problemes[] = "{$cliniques} cliniques (max {$limites['cliniques']})";
        }

        $employes = $this->employesActifs();
        if ($limites['employes'] !== null && $employes > $limites['employes']) {
            $problemes[] = "{$employes} employés actifs (max {$limites['employes']})";
        }

        $patients = $this->patients()->count();
        if ($limites['patients'] !== null && $patients > $limites['patients']) {
            $problemes[] = "{$patients} patients (max {$limites['patients']})";
        }

        if ($limites['stockage_go'] !== null && (float) $this->usage_stockage_go > $limites['stockage_go']) {
            $problemes[] = "{$this->usage_stockage_go} Go de stockage (max {$limites['stockage_go']} Go)";
        }

        return $problemes;
    }

    /**
     * Adresses des administrateurs (rôles du domaine organisation, accès
     * actif) : factures, relances, plafonds. Pour une alerte, sans ceux qui
     * en ont coupé les e-mails dans leurs réglages.
     *
     * @return list<string>
     */
    public function emailsAdministrateurs(?TypeAlerte $alerte = null): array
    {
        $roles = Role::withoutGlobalScopes()
            ->where('organisation_id', $this->id)
            ->get()
            ->filter(fn (Role $role) => $role->domaines->contains(DomainePermission::Organisation))
            ->pluck('id');

        return Employe::withoutGlobalScopes()
            ->where('organisation_id', $this->id)
            ->whereIn('role_id', $roles)
            ->where('acces_suspendu', false)
            ->with('user')
            ->get()
            ->filter(fn (Employe $employe) => ! $alerte || $employe->recoitAlertesEmail($alerte))
            ->map(fn (Employe $employe) => $employe->user?->email)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** La formule plafonne les employés actifs — garde de l'embauche et du rétablissement d'accès. */
    public function limiteEmployesAtteinte(): bool
    {
        $limite = $this->plan->limites()['employes'];

        return $limite !== null && $this->employesActifs() >= $limite;
    }
}
