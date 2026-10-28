<?php

namespace App\Http\Requests\V1\DossierMedical;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Archivage au dossier (certificats, consentements signés, comptes
 * rendus PDF). Un PDF peut s'y joindre (demande de Jim, 28.10.2026 : le
 * rapport ou le compte rendu d'un confrère), 25 Mo au plus ; le
 * propriétaire ne le voit que si la clinique le partage.
 */
class EnregistrerDocumentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'etabli_le' => ['sometimes', 'date', 'before_or_equal:'.aujourdhui()->toDateString()],
            'fichier' => ['sometimes', 'file', 'mimetypes:application/pdf', 'max:25600', function (string $attribut, mixed $fichier, Closure $echec): void {
                // Le contenu, pas le nom : un PDF commence par « %PDF- » dans son premier kilo-octet
                $debut = $fichier instanceof UploadedFile ? (string) @file_get_contents((string) $fichier->getRealPath(), false, null, 0, 1024) : '';
                if (! str_contains($debut, '%PDF-')) {
                    $echec('Seul un PDF peut rejoindre le dossier.');
                }
            }],
            'visible_proprietaire' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fichier.mimetypes' => 'Seul un PDF peut rejoindre le dossier.',
            'fichier.max' => 'Ce PDF dépasse 25 Mo.',
        ];
    }
}
