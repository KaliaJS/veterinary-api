<?php

namespace App\Models;

use App\Auth\ContexteClinique;
use App\Enums\StatutAbsence;
use App\Enums\StatutContrat;
use App\Enums\TypeAbsence;
use App\Enums\TypeAlerte;
use App\Models\Concerns\AppartientAUneOrganisation;
use App\Models\Concerns\AUnePhoto;
use Database\Factories\EmployeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Fiche RH d'une personne dans une organisation (contrat, salaire,
 * congés, rôle) : une seule, quel que soit le nombre de cliniques où
 * elle travaille. Ses cliniques sont ses affectations (`cliniques()`).
 * Deux scopes : l'organisation du jeton, et la clinique du jeton
 * (`'clinique'`, les employés qui y sont affectés) — l'équipe d'une
 * clinique. Lever `'clinique'` donne toute l'organisation.
 */
class Employe extends Model
{
    /** @use HasFactory<EmployeFactory> */
    use AppartientAUneOrganisation, AUnePhoto, HasFactory;

    /** Miroir des défauts de la base, connus dès la création (sans relire la ligne) */
    protected $attributes = [
        'acces_suspendu' => false,
    ];

    protected $fillable = [
        'organisation_id', 'user_id', 'nom', 'initiales', 'role_libelle', 'role_id',
        'taux', 'contrat', 'entree_le', 'tel', 'email', 'photo_chemin', 'conges_droit', 'conges_pris',
        'acces_suspendu', 'contrat_statut', 'salaire_mensuel',
        'licenciement_notifie_le', 'fin_contrat_le', 'licenciement_motif',
        'alertes_email_coupees', 'alertes_lues',
    ];

    protected function casts(): array
    {
        return [
            'alertes_email_coupees' => 'array',
            'alertes_lues' => 'array',
            'entree_le' => 'date',
            'taux' => 'integer',
            'conges_droit' => 'integer',
            'conges_pris' => 'integer',
            'acces_suspendu' => 'boolean',
            'accord_site_le' => 'datetime',
            'contrat_statut' => StatutContrat::class,
            'salaire_mensuel' => 'decimal:2',
            'licenciement_notifie_le' => 'date',
            'fin_contrat_le' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('clinique', function (Builder $builder): void {
            $contexte = app(ContexteClinique::class);
            if ($contexte->estDefini()) {
                $builder->whereIn($builder->qualifyColumn('id'), fn ($affectations) => $affectations
                    ->select('employe_id')
                    ->from('clinique_employe')
                    ->where('clinique_id', $contexte->cliniqueId()));
            }
        });
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** Vétérinaire de métier (l'intitulé de la fiche, pas le rôle : un administrateur peut l'être). */
    public function estVeterinaire(): bool
    {
        return str_starts_with((string) $this->role_libelle, 'Vétérinaire');
    }

    /** Reçoit-elle les e-mails de ce type d'alerte ? (oui, sauf si elle les a coupés) */
    public function recoitAlertesEmail(TypeAlerte $type): bool
    {
        return ! in_array($type->value, $this->alertes_email_coupees ?? [], true);
    }

    /**
     * Cliniques où la personne travaille (affectations).
     *
     * @return BelongsToMany<Clinique, $this>
     */
    public function cliniques(): BelongsToMany
    {
        return $this->belongsToMany(Clinique::class)->withTimestamps()->orderBy('cliniques.id');
    }

    /** Rôle configurable portant les permissions (`role_libelle`
     * reste le libellé affiché).
     *
     * @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return HasMany<DerogationPermission, $this> */
    public function derogations(): HasMany
    {
        return $this->hasMany(DerogationPermission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Colonne du planning dans la clinique du jeton — seuls les
     * vétérinaires en ont une (une par clinique où ils consultent).
     *
     * @return HasOne<LignePlanning, $this> */
    public function lignePlanning(): HasOne
    {
        return $this->hasOne(LignePlanning::class);
    }

    /** @return HasMany<Absence, $this> */
    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    /**
     * Congés validés en cours ou à venir, la part qui pèse sur le solde.
     * Sans le scope de clinique des absences : l'employé est déjà
     * autorisé, et l'écran Organisation montre aussi les employés qui ne
     * travaillent pas dans la clinique du jeton, dont les congés seraient
     * sinon écartés.
     *
     * @return HasMany<Absence, $this>
     */
    public function congesAVenir(): HasMany
    {
        return $this->hasMany(Absence::class)
            ->withoutGlobalScope('clinique')
            ->where('type', TypeAbsence::Conge)
            ->where('statut', StatutAbsence::Validee)
            ->whereDate('fin_le', '>=', aujourdhui());
    }

    /**
     * Solde de congés — DÉRIVÉ, jamais stocké (docs/data-model.md §1) :
     * le droit annuel moins les jours pris et les congés validés à
     * venir (la part restante d'un congé en cours compte).
     *
     * @return array{droit: int, pris: int, planifies: int, solde: int}
     */
    public function soldeConges(): array
    {
        $planifies = (int) $this->congesAVenir
            ->sum(function (Absence $absence): int {
                $depart = $absence->debut_le->greaterThan(aujourdhui()) ? $absence->debut_le : aujourdhui();

                return (int) $depart->diffInDays($absence->fin_le) + 1;
            });

        return [
            'droit' => $this->conges_droit,
            'pris' => $this->conges_pris,
            'planifies' => $planifies,
            'solde' => $this->conges_droit - $this->conges_pris - $planifies,
        ];
    }

    /**
     * Titre porté par le nom (« Dr », « Dre », « Pr »…) — le modèle de
     * données ne le sépare pas du nom (« Dr Anna Meyer »), il est dérivé.
     */
    public function titre(): ?string
    {
        return preg_match('/^(Dre?|Pre?|Prof)\.?\s+\S/u', $this->nom, $correspondance) === 1
            ? $correspondance[1]
            : null;
    }

    /** Compte dans la limite d'employés actifs de la formule. */
    public function estActif(): bool
    {
        return ! $this->acces_suspendu;
    }

    /** URL publique de la photo (nom de fichier imprévisible), ou null. */
    public function photoUrl(): ?string
    {
        // Servie seulement pour qui est là et l'accepte (23.10.2026, nLPD)
        return $this->photo_chemin && $this->accord_site_le !== null && $this->estPresentable()
            ? route('employe.photo', ['fichier' => basename($this->photo_chemin)])
            : null;
    }

    /** Présenté sur le site : accès actif, contrat pas encore terminé. */
    public function estPresentable(): bool
    {
        return ! $this->acces_suspendu && ($this->fin_contrat_le === null || $this->fin_contrat_le->isFuture());
    }

    public function estLicencie(): bool
    {
        return $this->licenciement_notifie_le !== null;
    }
}
