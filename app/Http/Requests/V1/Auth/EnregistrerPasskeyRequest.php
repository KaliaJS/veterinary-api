<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\V1\Auth\Concerns\PorteUnCredentialPasskey;
use Illuminate\Foundation\Http\FormRequest;

class EnregistrerPasskeyRequest extends FormRequest
{
    use PorteUnCredentialPasskey;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'defi_id' => ['required', 'string', 'max:26'],
            ...$this->reglesCredential(),
        ];
    }
}
