<?php

namespace App\Http\Requests\V1\RendezVous;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

/** Déplacement — horaire et/ou ligne, la durée est conservée. */
class DeplacerRendezVousRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required_without:ligne_planning_id', 'date'],
            'ligne_planning_id' => ['required_without:starts_at', $this->existeDansLaClinique('lignes_planning')],
        ];
    }
}
