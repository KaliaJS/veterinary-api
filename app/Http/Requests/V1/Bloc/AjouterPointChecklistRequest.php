<?php

namespace App\Http\Requests\V1\Bloc;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Point de checklist ajouté à la volée — le code est attribué par le
 * serveur, seuls ces points pourront être retirés.
 */
class AjouterPointChecklistRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'libelle' => ['required', 'string', 'max:255'],
        ];
    }
}
