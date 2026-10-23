<?php

namespace App\Http\Requests\V1\DossierMedical;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mêmes bornes que le front (poids valide : > 0 et ≤ 150 kg). Sans
 * date, la pesée est celle du jour — et remplace la pesée du même jour
 * si elle existe (règle du carnet).
 */
class EnregistrerPeseeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'poids_kg' => ['required', 'numeric', 'gt:0', 'max:150'],
            'pesee_le' => ['sometimes', 'date', 'before_or_equal:'.aujourdhui()->toDateString()],
        ];
    }
}
