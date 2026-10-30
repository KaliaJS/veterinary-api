<?php

namespace App\Http\Requests\V1\Videos;

use Illuminate\Foundation\Http\FormRequest;

/** Avant de payer : l'accès commence tout de suite et l'achat est définitif. */
class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['accept_terms' => ['accepted']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['accept_terms.accepted' => 'Cochez que l’accès commence tout de suite et que l’achat est définitif.'];
    }
}
