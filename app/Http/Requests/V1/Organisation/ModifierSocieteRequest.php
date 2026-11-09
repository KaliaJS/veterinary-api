<?php

namespace App\Http\Requests\V1\Organisation;

use App\Rules\IbanSuisse;
use App\Rules\NumeroIde;
use App\Support\PlanComptable;
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
            // Le compte de la QR-facture : IBAN ou QR-IBAN suisse (01.11.2026)
            'iban' => ['sometimes', 'nullable', 'string', 'max:42', new IbanSuisse],
            // Le plan comptable de l'export (09.11.2026) : un compte par usage, un code TVA par taux ; vide, celui par défaut
            'plan_comptable' => ['sometimes', 'array:comptes,codes_tva'],
            'plan_comptable.comptes' => ['sometimes', 'array:'.implode(',', array_keys(PlanComptable::COMPTES))],
            'plan_comptable.comptes.*' => ['nullable', 'string', 'max:12', 'regex:/^[0-9A-Za-z.\-]+$/'],
            'plan_comptable.codes_tva' => ['sometimes', 'array:'.implode(',', array_keys(PlanComptable::codesTvaParDefaut()))],
            'plan_comptable.codes_tva.*' => ['nullable', 'string', 'max:12', 'regex:/^[0-9A-Za-z.\-]+$/'],
        ];
    }
}
