<?php

namespace App\Http\Requests\V1\DossierMedical;

use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;

class EnregistrerVisiteRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motif' => ['required', 'string', 'max:255'],
            'visite_le' => ['sometimes', 'date', 'before_or_equal:today'],
            'employe_id' => ['sometimes', 'nullable', $this->employeDeLOrganisation()],
            'note' => ['nullable', 'string'],
        ];
    }
}
