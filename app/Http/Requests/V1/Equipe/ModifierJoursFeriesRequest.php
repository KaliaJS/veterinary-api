<?php

namespace App\Http\Requests\V1\Equipe;

use App\Auth\ContexteClinique;
use App\Models\Clinique;
use App\Support\JoursFeries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** La région du canton (quand il en a), les jours travaillés et les jours chômés en plus, parmi ceux de l'annexe du SECO. */
class ModifierJoursFeriesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $canton = Clinique::query()->find(app(ContexteClinique::class)->cliniqueId())?->canton();

        return [
            'variante' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(JoursFeries::CANTONS[$canton ?? ''] ?? []))],
            'retires' => ['sometimes', 'array', 'max:30'],
            'retires.*' => ['string', Rule::in(array_keys(JoursFeries::NOMS))],
            'ajoutes' => ['sometimes', 'array', 'max:30'],
            'ajoutes.*' => ['string', Rule::in(array_keys(JoursFeries::NOMS))],
        ];
    }
}
