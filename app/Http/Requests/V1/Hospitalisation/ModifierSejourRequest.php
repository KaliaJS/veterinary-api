<?php

namespace App\Http\Requests\V1\Hospitalisation;

use App\Auth\ContexteClinique;
use App\Enums\StatutSejour;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Models\Clinique;
use App\Models\Sejour;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Suivi d'un séjour en cours : état clinique, perfusion, note, sortie
 * prévue, vétérinaire référent — et changement de box (vers un box
 * libre de la capacité). L'admission et la sortie ont leurs gestes
 * propres ; un séjour sorti ne se modifie plus.
 */
class ModifierSejourRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'box' => ['sometimes', 'integer', 'min:1'],
            'motif' => ['sometimes', 'string', 'max:255'],
            'statut' => ['sometimes', Rule::enum(StatutSejour::class)],
            'veterinaire_id' => ['sometimes', 'nullable', $this->employeDeLOrganisation()],
            'sortie_prevue' => ['sometimes', 'nullable', 'string', 'max:255'],
            'perfusion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('box') || $validator->errors()->has('box')) {
                return;
            }

            $box = (int) $this->input('box');
            $sejour = $this->route('sejour');
            $clinique = Clinique::findOrFail(app(ContexteClinique::class)->cliniqueId());

            if ($box > $clinique->nombre_boxes) {
                $validator->errors()->add('box', "La clinique compte {$clinique->nombre_boxes} boxes.");

                return;
            }

            $occupe = Sejour::query()
                ->enCours()
                ->where('box', $box)
                ->whereKeyNot($sejour instanceof Sejour ? $sejour->id : null)
                ->exists();

            if ($occupe) {
                $validator->errors()->add('box', 'Ce box est occupé par un séjour en cours.');
            }
        });
    }
}
