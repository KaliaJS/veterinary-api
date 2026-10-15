<?php

namespace App\Models;

use Database\Factories\VaccinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vaccination extends Model
{
    /** @use HasFactory<VaccinationFactory> */
    use HasFactory;

    protected $fillable = [
        'patient_id', 'produit_id', 'nom', 'valences', 'marque', 'lot', 'gtin', 'peremption_le', 'voie',
        'site_injection', 'remarque', 'reaction', 'employe_id', 'injectee_le', 'echeance_le',
    ];

    protected function casts(): array
    {
        return [
            'valences' => 'array',
            'injectee_le' => 'date',
            'echeance_le' => 'date',
            'peremption_le' => 'date',
        ];
    }

    /** @return BelongsTo<ProduitVeterinaire, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitVeterinaire::class, 'produit_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Employe, $this> */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class);
    }
}
