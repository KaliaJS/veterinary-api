<?php

namespace App\Models;

use App\Enums\StatutDemandeRattachement;
use App\Models\Concerns\AppartientAUneOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un client réclame un animal enregistré par une clinique (numéro de
 * puce) ; la clinique accepte ou refuse. Niveau organisation : la
 * clinique la voit avec ses patients ; créée depuis l'espace, hors
 * contexte clinique, avec organisation_id explicite (celle du patient).
 */
class DemandeRattachement extends Model
{
    use AppartientAUneOrganisation;

    protected $table = 'demandes_rattachement';

    protected $fillable = [
        'organisation_id', 'patient_id', 'user_id', 'puce_saisie',
        'statut', 'traitee_le', 'traitee_par',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutDemandeRattachement::class,
            'traitee_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
