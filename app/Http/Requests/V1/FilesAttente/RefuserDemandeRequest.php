<?php

namespace App\Http\Requests\V1\FilesAttente;

use App\Enums\MotifRefusDemande;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Refus d'une demande en ligne : le motif écrit au propriétaire, un mot facultatif. */
class RefuserDemandeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motif' => ['required', Rule::enum(MotifRefusDemande::class)],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
