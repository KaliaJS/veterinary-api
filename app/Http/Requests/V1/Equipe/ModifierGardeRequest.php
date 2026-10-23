<?php

namespace App\Http\Requests\V1\Equipe;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Réattribution d'une garde de week-end : un samedi (à venir) → un
 * employé de la clinique.
 */
class ModifierGardeRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'samedi_le' => [
                'required',
                'date',
                'after_or_equal:'.aujourdhui()->toDateString(),
                function (string $attribut, mixed $valeur, \Closure $echec): void {
                    if (! CarbonImmutable::parse($valeur)->isSaturday()) {
                        $echec('La garde de week-end se réattribue sur un samedi.');
                    }
                },
            ],
            'employe_id' => ['required', $this->employeDeLaClinique()],
        ];
    }
}
