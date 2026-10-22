<?php

namespace App\Http\Requests\V1\Organisation;

use App\Enums\Espece;
use App\Enums\ModaliteUrgences;
use App\Enums\ServiceClinique;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Services d'une clinique, animaux soignés et affichage des
 * vétérinaires dans l'annuaire : tout l'état d'un coup (ce qui n'est
 * pas envoyé est retiré). L'hospitalisation demande son nombre de
 * boxes, les urgences leur modalité.
 */
class ModifierServicesCliniqueRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'services' => ['present', 'array'],
            'services.*.service' => ['required', 'distinct', Rule::enum(ServiceClinique::class)],
            'services.*.modalite' => ['nullable', Rule::enum(ModaliteUrgences::class)],
            'especes' => ['present', 'array'],
            'especes.*' => ['distinct', Rule::enum(Espece::class)],
            'nombre_boxes' => [
                Rule::requiredIf(fn (): bool => in_array(ServiceClinique::Hospitalisation->value, $this->codes(), true)),
                'nullable', 'integer', 'between:1,50',
            ],
            'affiche_veterinaires' => ['required', 'boolean'],
            'rdv_sans_compte' => ['sometimes', 'boolean'],
            'rdv_a_valider' => ['sometimes', 'boolean'],
            'urgences_a_valider' => ['sometimes', 'boolean'],
            // De 5 à 500 francs ; null : pas d'acompte
            'acompte_centimes' => ['sometimes', 'nullable', 'integer', 'min:500', 'max:50000'],
            'acompte_delai_heures' => ['sometimes', 'integer', 'min:0', 'max:336'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('services') as $i => $ligne) {
                if (($ligne['service'] ?? null) === ServiceClinique::Urgences->value && empty($ligne['modalite'])) {
                    $validator->errors()->add("services.{$i}.modalite", 'Précisez les urgences : 24 h sur 24 ou pendant les heures d’ouverture.');
                }
            }
        }];
    }

    /** @return list<string> codes des services envoyés */
    public function codes(): array
    {
        return array_values(array_filter(array_map(fn ($ligne) => is_array($ligne) ? ($ligne['service'] ?? null) : null, (array) $this->input('services'))));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nombre_boxes.required' => 'Indiquez le nombre de boxes d’hospitalisation.',
            'nombre_boxes.between' => 'Entre 1 et 50 boxes.',
        ];
    }
}
