<?php

namespace App\Http\Requests\V1\RendezVous;

use App\Enums\Espece;
use App\Enums\TypeRendezVous;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création rapide au prochain créneau libre du jour (bouton
 * « Rendez-vous », « Prendre RDV » d'une fiche patient) — 20 min par
 * défaut, la fiche se complète après.
 */
class ProchainCreneauRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date'],
            'duree_minutes' => ['sometimes', 'integer', 'min:10', 'max:1440'],
            'type' => ['sometimes', Rule::enum(TypeRendezVous::class)],
            'patient_id' => ['nullable', $this->existeDansLOrganisation('patients')],
            'patient_name' => ['nullable', 'string', 'max:255'],
            'species' => ['nullable', Rule::enum(Espece::class)],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
