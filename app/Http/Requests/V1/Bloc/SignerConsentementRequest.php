<?php

namespace App\Http\Requests\V1\Bloc;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Consentement éclairé (23.10.2026) : la signature tracée par la
 * personne qui consent (image PNG, moins de 300 Ko) et son nom. Ils
 * restent au dossier comme preuve.
 */
class SignerConsentementRequest extends FormRequest
{
    public const string PREFIXE = 'data:image/png;base64,';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'signataire' => ['required', 'string', 'max:255'],
            'signature' => ['required', 'string', 'max:400000', function (string $attribut, mixed $valeur, Closure $echec): void {
                if ($this->signaturePng() === null) {
                    $echec('La signature doit être une image tracée dans le cadre.');
                }
            }],
        ];
    }

    /** Le PNG décodé, s'il en est bien un, de taille raisonnable. */
    public function signaturePng(): ?string
    {
        $valeur = (string) $this->input('signature');
        if (! str_starts_with($valeur, self::PREFIXE)) {
            return null;
        }

        $png = base64_decode(substr($valeur, strlen(self::PREFIXE)), true);
        if ($png === false || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            return null;
        }

        $taille = @getimagesizefromstring($png);
        if ($taille === false || $taille[0] < 40 || $taille[1] < 20 || $taille[0] > 3000 || $taille[1] > 2000) {
            return null;
        }

        return $png;
    }
}
