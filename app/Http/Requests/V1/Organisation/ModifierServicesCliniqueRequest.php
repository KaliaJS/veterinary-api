<?php

namespace App\Http\Requests\V1\Organisation;

use App\Auth\ContexteClinique;
use App\Enums\Espece;
use App\Enums\ModaliteUrgences;
use App\Enums\ServiceClinique;
use App\Models\Clinique;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Services d'une clinique, animaux soignés, affichage des vétérinaires
 * dans l'annuaire et rendez-vous en ligne. Depuis le 04.11.2026, chaque
 * champ est facultatif : seul ce qui est envoyé change (la fiche envoie
 * le champ touché, deux gestes rapides ne s'annulent plus). La liste des
 * services et celle des animaux, quand elles sont envoyées, remplacent la
 * précédente. L'hospitalisation demande son nombre de boxes si la clinique
 * n'en a pas encore, les urgences leur modalité.
 *
 * Un seul élément à la fois (deuxième passe, 04.11.2026) : `service` +
 * `service_enabled` (et `modalite` pour les urgences) allume ou éteint un
 * service, `espece` + `espece_enabled` un animal soigné, sans toucher aux
 * autres. Deux onglets ouverts sur une liste périmée ne s'éteignent plus
 * l'un l'autre.
 */
class ModifierServicesCliniqueRequest extends FormRequest
{
    /**
     * Une clinique hors de l'organisation du jeton : 404 avant toute
     * validation (la règle des boxes lit la clinique visée, troisième passe).
     */
    protected function prepareForValidation(): void
    {
        abort_unless(app(ContexteClinique::class)->clinique()->societe->organisation->cliniques()->whereKey((int) $this->route('clinique'))->exists(), 404);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'services' => ['sometimes', 'array'],
            'services.*.service' => ['required', 'distinct', Rule::enum(ServiceClinique::class)],
            'services.*.modalite' => ['nullable', Rule::enum(ModaliteUrgences::class)],
            'service' => ['sometimes', Rule::enum(ServiceClinique::class)],
            'service_enabled' => ['required_with:service', 'boolean'],
            'modalite' => ['nullable', Rule::enum(ModaliteUrgences::class)],
            'espece' => ['sometimes', Rule::enum(Espece::class)],
            'espece_enabled' => ['required_with:espece', 'boolean'],
            'especes' => ['sometimes', 'array'],
            'especes.*' => ['distinct', Rule::enum(Espece::class)],
            'nombre_boxes' => [
                Rule::requiredIf(fn (): bool => (in_array(ServiceClinique::Hospitalisation->value, $this->codes(), true) || $this->allume(ServiceClinique::Hospitalisation))
                    && (int) Clinique::withoutGlobalScopes()->whereKey((int) $this->route('clinique'))->value('nombre_boxes') < 1),
                'nullable', 'integer', 'between:1,50',
            ],
            'affiche_veterinaires' => ['sometimes', 'boolean'],
            'rdv_sans_compte' => ['sometimes', 'boolean'],
            'rdv_a_valider' => ['sometimes', 'boolean'],
            'urgences_a_valider' => ['sometimes', 'boolean'],
            // De 5 à 500 francs ; null : pas d'acompte
            'acompte_centimes' => ['sometimes', 'nullable', 'integer', 'min:500', 'max:50000'],
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
            if ($this->allume(ServiceClinique::Urgences) && blank($this->input('modalite'))) {
                $validator->errors()->add('modalite', 'Précisez les urgences : 24 h sur 24 ou pendant les heures d’ouverture.');
            }
        }];
    }

    /** @return list<string> codes des services envoyés */
    public function codes(): array
    {
        return array_values(array_filter(array_map(fn ($ligne) => is_array($ligne) ? ($ligne['service'] ?? null) : null, (array) $this->input('services'))));
    }

    /** Ce service est-il allumé seul (`service` + `service_enabled`) ? */
    public function allume(ServiceClinique $service): bool
    {
        return $this->input('service') === $service->value && $this->boolean('service_enabled');
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
