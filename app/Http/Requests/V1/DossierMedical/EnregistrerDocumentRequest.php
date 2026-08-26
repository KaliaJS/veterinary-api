<?php

namespace App\Http\Requests\V1\DossierMedical;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Archivage au dossier (certificats, consentements signés, comptes
 * rendus PDF) — métadonnées seulement, le stockage du fichier réel
 * est un chantier à part (le front ne fait que télécharger le PDF).
 */
class EnregistrerDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'signataire' => ['nullable', 'string', 'max:255'],
            'etabli_le' => ['sometimes', 'date', 'before_or_equal:today'],
        ];
    }
}
