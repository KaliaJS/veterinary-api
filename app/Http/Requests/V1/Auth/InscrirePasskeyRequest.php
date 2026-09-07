<?php

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class InscrirePasskeyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'unique:users,email'],
            'defi_id' => ['required', 'string', 'max:26'],
            'nom' => ['sometimes', 'string', 'max:255'],
            'appareil' => ['sometimes', 'string', 'max:100'],
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'Un compte existe déjà pour cette adresse — connectez-vous.',
        ];
    }

    /** Réponse d'authentificateur brute, pour les services passkey. */
    public function credentialJson(): string
    {
        return json_encode($this->input('credential')) ?: '{}';
    }
}
