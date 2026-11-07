<?php

namespace App\Http\Requests\V1\DossierMedical;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Les quatre sections SOAP gardent les codes du front : `s` motif &
 * anamnèse, `o` examen clinique, `a` diagnostic, `p` traitement & plan.
 * `enregistrement_id` : le compte rendu part du brouillon d'un
 * enregistrement de la consultation, que cet enregistrement valide.
 * `version` : la version ouverte ; dépassée, l'enregistrement est refusé
 * (409) au lieu d'écraser le texte d'un autre (06.11.2026).
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
            'enregistrement_id' => ['nullable', 'integer'],
            'version' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
