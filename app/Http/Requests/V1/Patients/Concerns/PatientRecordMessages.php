<?php

namespace App\Http\Requests\V1\Patients\Concerns;

use Illuminate\Validation\Rules\Exists;

/**
 * La fiche de l'animal (07.11.2026) : le vétérinaire référent, un
 * vétérinaire de l'organisation (jamais un assistant), et les refus de la
 * race, de la robe, de l'assurance et du référent, dits en français.
 */
trait PatientRecordMessages
{
    protected function referringVetRule(): Exists
    {
        return $this->employeDeLOrganisation()->where(fn ($q) => $q->where('role_libelle', 'like', 'Vétérinaire%'));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $referringVet = 'Le vétérinaire référent doit être un vétérinaire de l’organisation.';

        return [
            'race.string' => 'La race doit être un texte.',
            'race.max' => 'La race ne doit pas dépasser :max caractères.',
            'coat.string' => 'La robe doit être un texte.',
            'coat.max' => 'La robe ne doit pas dépasser :max caractères.',
            'assurance.string' => 'L’assurance doit être un texte.',
            'assurance.max' => 'L’assurance ne doit pas dépasser :max caractères.',
            'referring_vet_id.integer' => $referringVet,
            'referring_vet_id.exists' => $referringVet,
        ];
    }
}
