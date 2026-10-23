<?php

namespace App\Http\Requests\V1\RendezVous;

use App\Enums\DecisionAnnulation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Annulation par la clinique : le motif, et pour un rendez-vous réservé avec acompte, ce qui en est rendu (tout par défaut). */
class AnnulerRendezVousRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motif' => ['nullable', 'string', 'max:255'],
            'acompte' => ['nullable', Rule::in([DecisionAnnulation::Complet->value, DecisionAnnulation::HorsFrais->value, DecisionAnnulation::Sans->value])],
        ];
    }
}
