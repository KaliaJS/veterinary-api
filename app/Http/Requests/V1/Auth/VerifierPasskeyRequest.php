<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\V1\Auth\Concerns\PorteUnCredentialPasskey;
use Illuminate\Foundation\Http\FormRequest;

class VerifierPasskeyRequest extends FormRequest
{
    use PorteUnCredentialPasskey;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'defi_id' => ['required', 'string', 'max:26'],
            'clinique_id' => ['sometimes', 'integer'],
            'appareil' => ['sometimes', 'string', 'max:100'],
            ...$this->reglesCredential(),
        ];
    }
}
