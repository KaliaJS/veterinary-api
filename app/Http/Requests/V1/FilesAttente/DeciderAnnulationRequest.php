<?php

namespace App\Http\Requests\V1\FilesAttente;

use App\Enums\DecisionAnnulation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Réponse à une demande d'annulation : rendre tout, rendre moins les frais, garder l'acompte, ou maintenir le rendez-vous. */
class DeciderAnnulationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(DecisionAnnulation::class)],
        ];
    }
}
