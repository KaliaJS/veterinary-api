<?php

namespace App\Http\Requests\V1\RendezVous;

use App\Enums\StatutRendezVous;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Seuls les statuts du cycle actif passent ici — `noshow` et `annule`
 * ont leurs endpoints dédiés (compteur no-show, motif d'annulation).
 * Le passage à `fini` facture l'acte dérivé du type, sauf demande
 * explicite (`sans_facture` — « Terminer sans générer »).
 */
class ChangerStatutRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                StatutRendezVous::Prevu->value,
                StatutRendezVous::Salle->value,
                StatutRendezVous::Cours->value,
                StatutRendezVous::Fini->value,
            ])],
            'sans_facture' => ['sometimes', 'boolean'],
        ];
    }
}
