<?php

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifierPasskeyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'defi_id' => ['required', 'string', 'max:26'],
            'clinique_id' => ['sometimes', 'integer'],
            'appareil' => ['sometimes', 'string', 'max:100'],
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ];
    }

    /** Réponse d'authentificateur brute, pour les services passkey. */
    public function credentialJson(): string
    {
        return json_encode($this->input('credential')) ?: '{}';
    }
}
