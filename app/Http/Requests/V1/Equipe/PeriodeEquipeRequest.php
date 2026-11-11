<?php

namespace App\Http\Requests\V1\Equipe;

use App\Support\DateSaisie;
use Illuminate\Foundation\Http\FormRequest;

/** Les jours d'une période du planning (nuits de garde, absences) : 100 au plus, dans les trois ans autour d'aujourd'hui. */
class PeriodeEquipeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $limite = DateSaisie::lire($this->input('du'))?->addDays(99)->toDateString() ?? 'du';

        return [
            'du' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:'.aujourdhui()->subYears(3)->toDateString()],
            'au' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:du', 'before_or_equal:'.$limite, 'before_or_equal:'.aujourdhui()->addYears(3)->toDateString()],
        ];
    }

    /** Une date envoyée en liste : une erreur de validation, jamais du serveur */
    protected function prepareForValidation(): void
    {
        foreach (['du', 'au'] as $champ) {
            if ($this->has($champ) && ! is_string($this->input($champ))) {
                $this->merge([$champ => '']);
            }
        }
    }
}
