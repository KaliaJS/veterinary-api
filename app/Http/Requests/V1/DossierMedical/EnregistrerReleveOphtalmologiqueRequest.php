<?php

namespace App\Http\Requests\V1\DossierMedical;

use App\Enums\ResultatFluoresceine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnregistrerReleveOphtalmologiqueRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'releve_le' => ['sometimes', 'date', 'before_or_equal:today'],
            'schirmer_od' => ['nullable', 'integer', 'min:0', 'max:60'],
            'schirmer_og' => ['nullable', 'integer', 'min:0', 'max:60'],
            'pio_od' => ['nullable', 'integer', 'min:0', 'max:120'],
            'pio_og' => ['nullable', 'integer', 'min:0', 'max:120'],
            'fluoresceine' => ['nullable', Rule::enum(ResultatFluoresceine::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
