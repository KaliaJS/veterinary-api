<?php

namespace App\Http\Requests\V1\Organisation;

use App\Rules\NumeroIde;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Modifier une société de l'organisation (23.10.2026) : nom, IDE, et si
 * elle est inscrite au registre de la TVA. Le taux de TVA des factures
 * déjà émises ne change pas : il est figé à l'émission.
 */
class ModifierSocieteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('ide')) {
            $ide = trim((string) $this->input('ide'));
            $this->merge(['ide' => $ide === '' ? null : strtoupper($ide)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'string', 'max:255'],
            'ide' => ['sometimes', 'nullable', 'string', 'max:30', new NumeroIde],
            'assujettie_tva' => ['sometimes', 'boolean'],
        ];
    }
}
