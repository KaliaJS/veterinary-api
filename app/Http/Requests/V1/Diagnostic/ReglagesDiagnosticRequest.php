<?php

namespace App\Http\Requests\V1\Diagnostic;

use Illuminate\Foundation\Http\FormRequest;

/** Plafond des suggestions diagnostiques de l'organisation, en francs par période. */
class ReglagesDiagnosticRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plafond' => ['required', 'numeric', 'min:0', 'max:10000'],
        ];
    }
}
