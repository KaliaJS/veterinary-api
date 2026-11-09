<?php

namespace App\Models;

use App\Models\Concerns\AppartientAUneOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Une entrée du journal des traitements d'une exploitation (OMédV art. 28
 * al. 1, 09.11.2026) : dates de la première et de la dernière utilisation,
 * animaux ou groupe, indication, dénomination commerciale, quantité,
 * délais d'attente et dates de libération de chaque denrée, et qui a
 * prescrit, remis ou administré.
 */
class TreatmentEntry extends Model
{
    use AppartientAUneOrganisation;

    /** Les délais légaux d'une reconversion sans délai connu (art. 13 al. 2 let. c) */
    public const array LEGAL_DEFAULTS = ['milk' => 7, 'eggs' => 10, 'meat' => 28];

    protected $fillable = [
        'organisation_id', 'clinique_id', 'client_id', 'herd_id', 'patient_id', 'animals', 'first_use_on', 'last_use_on',
        'indication', 'produit_id', 'product_name', 'quantity', 'withdrawal_milk_days', 'withdrawal_meat_days',
        'withdrawal_eggs_days', 'legal_defaults', 'person_name', 'farm_visit_id', 'facture_id', 'recorded_by_id',
    ];

    protected function casts(): array
    {
        return ['first_use_on' => 'date:Y-m-d', 'last_use_on' => 'date:Y-m-d', 'legal_defaults' => 'boolean'];
    }

    /**
     * La date de libération de chaque denrée : la dernière utilisation plus
     * son délai ; null sans délai pour cette denrée.
     *
     * @return array{milk: string|null, meat: string|null, eggs: string|null}
     */
    public function releaseDates(): array
    {
        $fin = CarbonImmutable::parse($this->last_use_on);
        $date = fn (?int $jours): ?string => $jours === null ? null : $fin->addDays($jours)->toDateString();

        return [
            'milk' => $date($this->withdrawal_milk_days),
            'meat' => $date($this->withdrawal_meat_days),
            'eggs' => $date($this->withdrawal_eggs_days),
        ];
    }
}
