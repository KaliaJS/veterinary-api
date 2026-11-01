<?php

namespace App\Http\Requests\V1\Stupefiants;

use App\Enums\TypeMouvementStupefiant;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Models\MouvementStupefiant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Une ligne du registre des stupéfiants (mise aux normes, 01.11.2026) :
 * son type dit ce qu'il faut. La quantité est saisie positive, le type en
 * donne le sens ; une correction est signée (elle redresse la ligne
 * qu'elle pointe) ; un inventaire donne la quantité comptée, l'écart se
 * calcule. Administration et remise : l'animal (la remise, avec
 * l'ordonnance). Réception et retour : le fournisseur. Perte, vol,
 * élimination, correction et écart d'inventaire : ce qui s'est passé.
 */
class EnregistrerMouvementStupefiantRequest extends FormRequest
{
    use ReglesScopees;

    /** Le droit d'inscrire avant tout : sans lui, un 403, pas une erreur de saisie */
    public function authorize(): bool
    {
        return $this->user()?->can('create', MouvementStupefiant::class) ?? false;
    }

    private function type(): ?TypeMouvementStupefiant
    {
        return TypeMouvementStupefiant::tryFrom((string) $this->input('type'));
    }

    private function est(TypeMouvementStupefiant ...$types): bool
    {
        return in_array($this->type(), $types, true);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $t = TypeMouvementStupefiant::class;

        return [
            'produit_id' => ['required', $this->existeDansLOrganisation('produits')->where('est_stupefiant', true)],
            'type' => ['required', Rule::enum($t)],
            'quantite' => [
                Rule::requiredIf(fn (): bool => ! $this->est($t::Inventaire, $t::Visa)),
                'nullable', 'numeric', 'decimal:0,3',
                $this->est($t::Correction) ? 'between:-99999.999,99999.999' : 'between:0.001,99999.999',
                Rule::notIn($this->est($t::Correction) ? [0] : []),
            ],
            'quantite_comptee' => [Rule::requiredIf(fn (): bool => $this->est($t::Inventaire)), 'nullable', 'numeric', 'decimal:0,3', 'between:0,99999.999'],
            'patient_id' => [Rule::requiredIf(fn (): bool => $this->est($t::Administration, $t::Remise)), 'nullable', $this->existeDansLOrganisation('patients')],
            'ordonnance_numero' => [Rule::requiredIf(fn (): bool => $this->est($t::Remise)), 'nullable', 'string', 'max:30'],
            'fournisseur' => [Rule::requiredIf(fn (): bool => $this->est($t::Reception, $t::Retour)), 'nullable', 'string', 'max:255'],
            'bulletin' => ['nullable', 'string', 'max:60'],
            'bulletin_le' => ['nullable', 'date', 'before_or_equal:today'],
            'corrige_id' => [Rule::requiredIf(fn (): bool => $this->est($t::Correction)), 'nullable', 'integer'],
            'motif' => [Rule::requiredIf(fn (): bool => $this->est($t::Perte, $t::Vol, $t::Elimination, $t::Correction)), 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'produit_id.exists' => 'Ce produit n’est pas marqué stupéfiant dans le catalogue.',
            'quantite.required' => 'Indiquez la quantité.',
            'quantite.between' => 'Indiquez une quantité positive, avec au plus trois décimales.',
            'quantite.not_in' => 'Une correction a une quantité, en plus ou en moins.',
            'quantite_comptee.required' => 'Indiquez la quantité comptée.',
            'patient_id.required' => 'Indiquez l’animal.',
            'ordonnance_numero.required' => 'Indiquez l’ordonnance de la remise.',
            'fournisseur.required' => 'Indiquez le fournisseur.',
            'corrige_id.required' => 'Choisissez la ligne à corriger.',
            'motif.required' => match ($this->type()) {
                TypeMouvementStupefiant::Elimination => 'Indiquez comment la substance a été éliminée (par exemple l’envoi recommandé au pharmacien cantonal).',
                TypeMouvementStupefiant::Correction => 'Indiquez pourquoi vous corrigez.',
                default => 'Décrivez ce qui s’est passé.',
            },
        ];
    }
}
