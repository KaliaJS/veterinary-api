<?php

namespace App\Http\Requests\V1\RendezVous;

use App\Enums\DrapeauRendezVous;
use App\Enums\Espece;
use App\Enums\TypeRendezVous;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnregistrerRendezVousRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ligne_planning_id' => ['required', $this->existeDansLaClinique('lignes_planning')],
            'starts_at' => ['required', 'date'],
            'duree_minutes' => ['required', 'integer', 'min:10', 'max:1440'],
            'type' => ['sometimes', Rule::enum(TypeRendezVous::class)],
            'salle_id' => ['nullable', $this->existeDansLaClinique('salles')],
            'patient_id' => ['nullable', $this->existeDansLOrganisation('patients')],
            'patient_name' => ['nullable', 'string', 'max:255'],
            'species' => ['nullable', Rule::enum(Espece::class)],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
            'note_accueil' => ['nullable', 'string', 'max:1000'],
            'flags' => ['sometimes', 'array'],
            'flags.*' => [Rule::enum(DrapeauRendezVous::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note_accueil.max' => 'La note de l’accueil tient en 1000 caractères au plus.',
            'note_accueil.string' => 'La note de l’accueil est un texte.',
        ];
    }
}
