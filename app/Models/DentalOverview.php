<?php

namespace App\Models;

use App\Enums\CalculusGrade;
use App\Enums\GingivitisGrade;
use App\Enums\Occlusion;
use App\Models\Concerns\RecordsSourceOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le bilan de la bouche d'un patient (schéma dentaire, 04.11.2026) :
 * gingivite, tartre, occlusion et bloc local. Une ligne par patient,
 * chaque valeur facultative (non relevée).
 */
class DentalOverview extends Model
{
    use RecordsSourceOrganisation;

    protected $fillable = ['patient_id', 'gingivitis', 'calculus', 'occlusion', 'local_block', 'source_organisation_id'];

    protected function casts(): array
    {
        return [
            'gingivitis' => GingivitisGrade::class,
            'calculus' => CalculusGrade::class,
            'occlusion' => Occlusion::class,
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Ce que le front lit ; sans ligne, tout est à null.
     *
     * @return array{gingivitis: string|null, calculus: string|null, occlusion: string|null, local_block: string|null}
     */
    public static function pourSchema(?self $bilan): array
    {
        return [
            'gingivitis' => $bilan?->gingivitis?->value,
            'calculus' => $bilan?->calculus?->value,
            'occlusion' => $bilan?->occlusion?->value,
            'local_block' => $bilan?->local_block,
        ];
    }
}
