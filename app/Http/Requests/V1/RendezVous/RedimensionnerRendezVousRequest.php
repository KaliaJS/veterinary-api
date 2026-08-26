<?php

namespace App\Http\Requests\V1\RendezVous;

use Illuminate\Foundation\Http\FormRequest;

/** Durée minimale : 10 min (docs/data-model.md §3 — placement). */
class RedimensionnerRendezVousRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'duree_minutes' => ['required', 'integer', 'min:10', 'max:1440'],
        ];
    }
}
