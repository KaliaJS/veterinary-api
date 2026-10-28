<?php

namespace App\Http\Requests\V1\Imagerie;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Un diagnostic confirmé ou infirmé après coup : étiquette, résultat, source, date, document éventuel. */
class ConfirmationRadioRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'etiquette' => ['required', 'string', Rule::in(array_keys(config('imagerie.etiquettes')))],
            'resultat' => ['required', 'string', Rule::in(array_keys(config('imagerie.resultats')))],
            'source' => ['required', 'string', Rule::in(array_keys(config('imagerie.sources')))],
            'constate_le' => ['required', 'date', 'before_or_equal:tomorrow'],
            'document_id' => ['sometimes', 'nullable', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'etiquette.required' => 'Choisissez un diagnostic.',
            'etiquette.in' => 'Choisissez une étiquette de la liste.',
            'resultat.required' => 'Choisissez « Confirmé » ou « Infirmé ».',
            'source.required' => 'Choisissez ce qui l’a confirmé.',
            'constate_le.required' => 'Indiquez la date.',
            'constate_le.date' => 'Cette date n’est pas valide.',
            'document_id.integer' => 'Ce document n’est pas dans le dossier de cet animal.',
            'note.max' => 'La note dépasse 2000 caractères.',
            'resultat.in' => 'Choisissez « Confirmé » ou « Infirmé ».',
            'source.in' => 'Choisissez une source de la liste.',
            'constate_le.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ];
    }
}
