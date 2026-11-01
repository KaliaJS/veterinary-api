<?php

namespace App\Http\Requests\V1\Stupefiants;

use App\Enums\StatutOrdonnance;
use App\Enums\TypeMouvementStupefiant;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Models\MouvementStupefiant;
use App\Models\Ordonnance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Une ligne du registre des stupéfiants (mise aux normes, 01.11.2026) :
 * son type dit ce qu'il faut. La quantité est saisie positive, le type en
 * donne le sens ; une correction est signée (elle redresse la ligne
 * qu'elle pointe) ; un inventaire donne la quantité comptée, l'écart se
 * calcule. Administration et remise : l'animal (la remise, avec
 * l'ordonnance de l'app qui la prescrit). Réception et retour : le fournisseur. Perte, vol,
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
            // Le visa passe par son chemin à lui, réservé au vétérinaire responsable (POST /stupefiants/visa, 01.11.2026)
            'type' => ['required', Rule::enum($t)->except([$t::Visa])],
            'quantite' => [
                Rule::requiredIf(fn (): bool => ! $this->est($t::Inventaire, $t::Visa)),
                'nullable', 'numeric', 'decimal:0,3',
                $this->est($t::Correction) ? 'between:-99999.999,99999.999' : 'between:0.001,99999.999',
                function (string $attribut, mixed $valeur, \Closure $echec): void {
                    if (is_numeric($valeur) && (float) $valeur === 0.0) {
                        $echec('Une correction a une quantité, en plus ou en moins.');
                    }
                },
            ],
            'quantite_comptee' => [Rule::requiredIf(fn (): bool => $this->est($t::Inventaire)), 'nullable', 'numeric', 'decimal:0,3', 'between:0,99999.999'],
            'patient_id' => [Rule::requiredIf(fn (): bool => $this->est($t::Administration, $t::Remise)), 'nullable', $this->existeDansLOrganisation('patients')],
            // Une remise : une ordonnance de l'app, en cours, signée pour cet animal, qui prescrit cette substance (01.11.2026)
            'ordonnance_id' => [
                Rule::requiredIf(fn (): bool => $this->est($t::Remise)), 'nullable', 'integer',
                function (string $attribut, mixed $valeur, \Closure $echec): void {
                    $valable = Ordonnance::query()->whereKey((int) $valeur)
                        ->where('patient_id', (int) $this->input('patient_id'))
                        ->where('statut', StatutOrdonnance::Active)
                        ->whereHas('lignes', fn ($ligne) => $ligne->where('produit_id', (int) $this->input('produit_id')))
                        ->exists();
                    if (! $valable) {
                        $echec('Choisissez une ordonnance en cours de cet animal qui prescrit cette substance.');
                    }
                },
            ],
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
            'type.enum' => $this->input('type') === 'visa' ? 'Le visa se donne par « Viser le registre », réservé au vétérinaire responsable.' : 'Choisissez le type de la ligne.',
            'quantite.required' => 'Indiquez la quantité.',
            'quantite.between' => 'Indiquez une quantité positive, avec au plus trois décimales.',
            'quantite.not_in' => 'Une correction a une quantité, en plus ou en moins.',
            'quantite_comptee.required' => 'Indiquez la quantité comptée.',
            'patient_id.required' => 'Indiquez l’animal.',
            'ordonnance_id.required' => 'Choisissez l’ordonnance de la remise.',
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
