<?php

namespace App\Http\Requests\V1\Equipe;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Licenciement (écran Organisation — docs/data-model.md §2) : l'API
 * garde l'essentiel — motif, fin de contrat, libération immédiate de
 * l'accès. Le détail du formulaire (soldes, documents de sortie, kit
 * matériel) vit dans le PDF archivé au dossier RH.
 */
class LicencierEmployeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fin_contrat_le' => ['required', 'date', 'after_or_equal:'.aujourdhui()->toDateString()],
            'motif' => ['nullable', 'string', 'max:255'],
            'revoquer_acces' => ['sometimes', 'boolean'],
        ];
    }
}
