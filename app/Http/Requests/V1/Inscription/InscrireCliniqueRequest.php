<?php

namespace App\Http\Requests\V1\Inscription;

/** Le vétérinaire, sa société (IDE facultatif) et sa première clinique. */
class InscrireCliniqueRequest extends GlnRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $ide = trim((string) $this->input('societe.ide'));
        $this->merge(['societe' => [...(array) $this->input('societe', []), 'ide' => $ide === '' ? null : strtoupper($ide)]]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'societe' => ['required', 'array'],
            'societe.nom' => ['required', 'string', 'max:255'],
            'societe.ide' => ['nullable', 'string', 'regex:/^CHE-\d{3}\.\d{3}\.\d{3}$/'],
            'clinique' => ['required', 'array'],
            'clinique.nom' => ['required', 'string', 'max:100'],
            'clinique.rue' => ['required', 'string', 'max:255'],
            'clinique.npa' => ['required', 'string', 'regex:/^\d{4}$/'],
            'clinique.ville' => ['required', 'string', 'max:100'],
            'clinique.tel' => ['nullable', 'string', 'max:30'],
            'clinique.email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'societe.ide.regex' => 'Le numéro IDE s’écrit CHE-123.456.789.',
            'clinique.npa.regex' => 'Le NPA compte quatre chiffres.',
        ];
    }
}
