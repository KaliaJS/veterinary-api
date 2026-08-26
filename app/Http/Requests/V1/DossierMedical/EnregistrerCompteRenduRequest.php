<?php

namespace App\Http\Requests\V1\DossierMedical;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Les quatre sections SOAP gardent les codes du front : `s` motif &
 * anamnèse, `o` examen clinique, `a` diagnostic, `p` traitement & plan.
 */
class EnregistrerCompteRenduRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            's' => ['nullable', 'string'],
            'o' => ['nullable', 'string'],
            'a' => ['nullable', 'string'],
            'p' => ['nullable', 'string'],
        ];
    }
}
