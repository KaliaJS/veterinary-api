<?php

namespace App\Http\Requests\V1\DossierMedical;

use App\Enums\EtatDent;
use App\Models\Patient;
use App\Support\DentalFormula;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Une dent du schéma dentaire, seule (04.11.2026) : ses états et sa note.
 * Sans état ni note, la dent redevient saine. Le numéro est celui de la
 * route, une dent de la formule de l'espèce.
 */
class ModifierDentRequest extends FormRequest
{
    /** Le droit avant la forme : un compte sans droit reçoit 403, pas 422 */
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $patient instanceof Patient && ($this->user()?->can('update', $patient) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['dent' => (string) $this->route('dent')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dent' => ['required', 'digits:3'],
            'states' => ['present', 'array', 'max:'.count(EtatDent::cases())],
            'states.*' => ['string', Rule::enum(EtatDent::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $patient = $this->route('patient');
            $formula = $patient instanceof Patient ? DentalFormula::teethFor($patient->espece) : null;
            if ($formula === null) {
                $validator->errors()->add('dent', 'Cette espèce n’a pas de schéma dentaire.');
            } elseif (! in_array((int) $this->input('dent'), $formula, true)) {
                $validator->errors()->add('dent', "« {$this->input('dent')} » n’est pas une dent de cette espèce.");
            }
            $etats = is_array($this->input('states')) ? array_values($this->input('states')) : [];
            foreach ($etats as $i => $etat) {
                if (array_search($etat, $etats, true) !== $i) {
                    $validator->errors()->add("states.{$i}", 'Cet état est déjà sur la dent.');
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'dent.digits' => 'Ce numéro de dent n’existe pas.',
            'states.present' => 'Les états de la dent manquent.',
            'states.*' => 'Cet état n’existe pas.',
            'note.max' => 'La note tient en 500 caractères.',
        ];
    }
}
