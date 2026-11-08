<?php

namespace App\Http\Resources\V1;

use App\Http\Controllers\Api\V1\Isabv\IsabvReferenceController;
use App\Models\AntibioticDeclaration;
use App\Models\AntibioticDeclarationLine;
use App\Models\IsabvConnection;
use App\Services\Isabv\DeclarationRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une déclaration SI ABV (08.11.2026), avec ce qui lui manque pour partir
 * et, pour une déclaration refusée, le message de l'OSAV.
 *
 * @mixin AntibioticDeclaration
 */
class AntibioticDeclarationResource extends JsonResource
{
    /** Le 20 du mois qui suit la consultation ou la remise */
    public static function deadline(CarbonInterface $visitOn): CarbonImmutable
    {
        return CarbonImmutable::instance($visitOn)->startOfMonth()->addMonth()->setDay(20);
    }

    public function toArray(Request $request): array
    {
        $rules = app(DeclarationRules::class);
        $isEditable = $this->status->isEditable();

        return [
            'id' => $this->id,
            'recipe_number' => $this->recipe_number,
            'form' => $this->form->value,
            'form_label' => $this->form->label(),
            'status' => $this->status->value,
            'visit_on' => $this->visit_on->toDateString(),
            'patient' => $this->whenLoaded('patient', fn () => $this->patient ? ['id' => $this->patient->id, 'name' => $this->patient->nom, 'species' => $this->patient->espece->value] : null),
            'vet' => $this->whenLoaded('employe', fn () => $this->employe?->nom),
            'source' => $this->source_type !== null ? ['type' => class_basename($this->source_type), 'id' => $this->source_id, 'number' => $this->whenLoaded('source', fn () => $this->source?->getAttribute('numero'))] : null,
            'species_code' => $this->species_code,
            'weight_kg' => $this->weight_kg,
            'diagnosis_key' => $this->diagnosis_key,
            'diagnosis_description' => $this->diagnosis_description,
            'is_antibiogram_done' => $this->is_antibiogram_done,
            'animals_count' => $this->animals_count,
            'animals_total' => $this->animals_total,
            'holding_number' => $this->holding_number,
            'holding_address' => $this->holding_address,
            'av_treatment_days' => $this->av_treatment_days,
            'av_daily_dose' => $this->av_daily_dose,
            'osav_message' => $this->osav_message,
            'sent_at' => $this->sent_at?->toIso8601String(),
            // O-SI ABV, art. 4, al. 2 : au plus tard le 20 du mois qui suit
            'deadline' => self::deadline($this->visit_on)->toDateString(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            // Une envoyée se corrige par Veterinary en variante 1 seulement (sinon sur le site de l'OSAV)
            'is_editable' => $isEditable || ($this->status->value === 'sent' && IsabvConnection::activeFor($this->clinique_id) !== null),
            'is_deactivated_only_on_web' => $this->isDeactivatedOnlyOnWeb(),
            'missing' => $isEditable ? $rules->missing($this->resource) : [],
            'web_only_reason' => $isEditable ? $rules->webOnlyReason($this->resource) : null,
            'lines' => $this->lines->map(fn (AntibioticDeclarationLine $line) => [
                'id' => $line->id,
                'product_id' => $line->produit_id,
                'product_name' => $line->relationLoaded('produit') ? $line->produit?->nom : null,
                'preparation' => $line->relationLoaded('preparation') && $line->preparation ? IsabvReferenceController::preparation($line->preparation) : ['code' => $line->preparation_code],
                'route_code' => $line->route_code,
                'dose_1' => $line->dose_1,
                'frequency_1' => $line->frequency_1,
                'days_1' => $line->days_1,
                'dose_2' => $line->dose_2,
                'frequency_2' => $line->frequency_2,
                'days_2' => $line->days_2,
                'total_quantity' => $line->total_quantity,
                'is_total_entered' => $line->is_total_entered,
                'is_given_entered' => $line->is_given_entered,
                'given_quantity' => $line->given_quantity,
                'is_critical_confirmed' => $line->is_critical_confirmed,
            ])->values(),
        ];
    }
}
