<?php

namespace App\Models;

use App\Enums\StatutAbsence;
use App\Enums\StatutContrat;
use App\Enums\TypeAbsence;
use App\Models\Concerns\AppartientAUneClinique;
use Database\Factories\EmployeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employe extends Model
{
    /** @use HasFactory<EmployeFactory> */
    use AppartientAUneClinique, HasFactory;

    protected $fillable = [
        'clinique_id', 'user_id', 'nom', 'initiales', 'role_libelle', 'role_id',
        'taux', 'contrat', 'entree_le', 'tel', 'email', 'conges_droit', 'conges_pris',
        'acces_suspendu', 'contrat_statut', 'salaire_mensuel',
        'licenciement_notifie_le', 'fin_contrat_le', 'licenciement_motif',
    ];

    protected function casts(): array
    {
        return [
            'entree_le' => 'date',
            'taux' => 'integer',
            'conges_droit' => 'integer',
            'conges_pris' => 'integer',
            'acces_suspendu' => 'boolean',
            'contrat_statut' => StatutContrat::class,
            'salaire_mensuel' => 'decimal:2',
            'licenciement_notifie_le' => 'date',
            'fin_contrat_le' => 'date',
        ];
    }

    /** @return BelongsTo<Clinique, $this> */
    public function clinique(): BelongsTo
    {
        return $this->belongsTo(Clinique::class);
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

    /** Colonne du planning — seuls les vétérinaires en ont une.
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
     * Solde de congés — DÉRIVÉ, jamais stocké (docs/data-model.md §1) :
     * le droit annuel moins les jours pris et les congés validés à
     * venir (la part restante d'un congé en cours compte).
     *
     * @return array{droit: int, pris: int, planifies: int, solde: int}
     */
    public function soldeConges(): array
    {
        $planifies = (int) $this->absences()
            ->where('type', TypeAbsence::Conge)
            ->where('statut', StatutAbsence::Validee)
            ->whereDate('fin_le', '>=', today())
            ->get()
            ->sum(function (Absence $absence): int {
                $depart = $absence->debut_le->greaterThan(today()) ? $absence->debut_le : today();

                return (int) $depart->diffInDays($absence->fin_le) + 1;
            });

        return [
            'droit' => $this->conges_droit,
            'pris' => $this->conges_pris,
            'planifies' => $planifies,
            'solde' => $this->conges_droit - $this->conges_pris - $planifies,
        ];
    }

    /** Compte dans la limite d'employés actifs de la formule. */
    public function estActif(): bool
    {
        return ! $this->acces_suspendu;
    }

    public function estLicencie(): bool
    {
        return $this->licenciement_notifie_le !== null;
    }
}
