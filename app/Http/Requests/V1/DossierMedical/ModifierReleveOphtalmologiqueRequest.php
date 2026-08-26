<?php

namespace App\Http\Requests\V1\DossierMedical;

use App\Enums\ResultatFluoresceine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModifierReleveOphtalmologiqueRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'releve_le' => ['sometimes', 'date', 'before_or_equal:today'],
            'schirmer_od' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'schirmer_og' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'pio_od' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
            'pio_og' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
            'fluoresceine' => ['sometimes', 'nullable', Rule::enum(ResultatFluoresceine::class)],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
