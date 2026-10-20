<?php

namespace App\Http\Requests\V1\Inscription;

use App\Services\Inscription\VerificationVeterinaire;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Le vétérinaire : prénom, nom et GLN (13 chiffres, contrôle de GS1). */
class GlnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['gln' => preg_replace('/\D/', '', (string) $this->input('gln'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prenom' => ['required', 'string', 'max:60'],
            'nom' => ['required', 'string', 'max:60'],
            'gln' => ['required', 'string', function (string $attribut, mixed $valeur, Closure $echec): void {
                if (! VerificationVeterinaire::glnValide((string) $valeur)) {
                    $echec('Ce GLN n’est pas valable : vérifiez ses 13 chiffres, le dernier contrôle les autres.');
                }
            }],
        ];
    }
}
