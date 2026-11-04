<?php

namespace App\Models;

use App\Models\Concerns\RecordsSourceOrganisation;
use Database\Factories\ReleveDentaireFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une dent annotée du schéma dentaire : ses états (valeurs de `EtatDent`,
 * plusieurs à la fois depuis le 04.11.2026) et une note facultative. Une
 * dent saine et sans note n'a pas de ligne.
 */
class ReleveDentaire extends Model
{
    /** @use HasFactory<ReleveDentaireFactory> */
    use HasFactory, RecordsSourceOrganisation;

    protected $table = 'releves_dentaires';

    protected $fillable = ['patient_id', 'dent', 'etats', 'note', 'source_organisation_id'];

    protected function casts(): array
    {
        return [
            'dent' => 'integer',
            'etats' => 'array',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Ce que le front lit pour la dent.
     *
     * @return array{states: list<string>, note: string|null}
     */
    public function pourSchema(): array
    {
        return ['states' => array_values($this->etats ?? []), 'note' => $this->note];
    }
}
