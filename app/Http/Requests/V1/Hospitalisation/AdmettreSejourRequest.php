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
 * Admission en hospitalisation : patient de l'organisation, box dans
 * la capacité de la clinique du jeton (`nombre_boxes`) et libre — un
 * box n'héberge qu'un séjour en cours. Le vétérinaire référent peut
 * être d'une clinique sœur, comme les signataires du dossier.
 */
class AdmettreSejourRequest extends FormRequest
{
    use ReglesScopees;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', $this->existeDansLOrganisation('patients')],
            'box' => ['required', 'integer', 'min:1'],
            'motif' => ['required', 'string', 'max:255'],
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
            // Un patient n'occupe qu'un box à la fois, toutes cliniques
            // de l'organisation confondues
            if (! $validator->errors()->has('patient_id')
                && Sejour::withoutGlobalScope('clinique')->enCours()->where('patient_id', (int) $this->input('patient_id'))->exists()) {
                $validator->errors()->add('patient_id', 'Ce patient est déjà hospitalisé.');
            }

            if ($validator->errors()->has('box')) {
                return;
            }

            $box = (int) $this->input('box');
            $clinique = Clinique::findOrFail(app(ContexteClinique::class)->cliniqueId());

            if ($box > $clinique->nombre_boxes) {
                $validator->errors()->add('box', "La clinique compte {$clinique->nombre_boxes} boxes.");

                return;
            }

            if (Sejour::query()->enCours()->where('box', $box)->exists()) {
                $validator->errors()->add('box', 'Ce box est occupé par un séjour en cours.');
            }
        });
    }
}
