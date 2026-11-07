<?php

namespace App\Http\Requests\V1\Stupefiants;

use App\Auth\ContexteClinique;
use App\Enums\StatutOrdonnance;
use App\Enums\TypeMouvementStupefiant;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Models\MouvementStupefiant;
use App\Models\Ordonnance;
use App\Models\Produit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * Le tableau de la substance, d'où dépend la remise (accord de Jim, 01.11.2026) : tableau a, le formulaire
     * officiel numéroté du canton (art. 50, al. 2 OCStup) ; tableau b, une ordonnance de l'app (art. 46, al. 3).
     */
    private function tableau(): ?string
    {
        return Produit::query()->whereKey((int) $this->input('produit_id'))->where('est_stupefiant', true)->value('tableau_stupefiant');
    }

    private function remiseSurFormulaire(): bool
    {
        return $this->est(TypeMouvementStupefiant::Remise) && $this->tableau() === 'a';
    }

    private function remiseSurOrdonnance(): bool
    {
        return $this->est(TypeMouvementStupefiant::Remise) && $this->tableau() === 'b';
    }

    /** Le numéro du formulaire officiel, sans ses espaces (« 7 012 345 ») */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('numero_lot'))) {
            $this->merge(['numero_lot' => trim($this->input('numero_lot'))]);
        }
        if (is_string($this->input('ordonnance_officielle'))) {
            // Sans espaces : « 7 012 345 » ; les zéros en tête s'ôtent après le contrôle (MouvementStupefiantController)
            $this->merge(['ordonnance_officielle' => (string) preg_replace('/[\s’\'.]/u', '', $this->input('ordonnance_officielle'))]);
        }
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Un lot reçu déjà périmé (le mois est passé, à l'heure de la clinique) : l'inscrire se confirme
            $peremption = $this->input('lot_peremption');
            $ceMois = now(app(ContexteClinique::class)->clinique()?->fuseau_horaire ?: 'Europe/Zurich')->format('Y-m');
            if ($this->est(TypeMouvementStupefiant::Reception) && is_string($peremption) && preg_match('/^\d{4}-\d{2}$/', $peremption) === 1
                && $peremption < $ceMois && ! $this->boolean('lot_perime_confirme')) {
                $validator->errors()->add('lot_peremption', 'Ce lot est déjà périmé : l’inscrire quand même ?');
            }
            if ($this->est(TypeMouvementStupefiant::Remise) && ! in_array($this->tableau(), ['a', 'b'], true) && ! $validator->errors()->has('produit_id')) {
                $validator->errors()->add('produit_id', 'Précisez d’abord le tableau de la substance (a ou b) : la remise en dépend.');
            }
        }];
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
            // Remise du tableau b : une ordonnance de l'app, en cours, signée pour cet animal, qui prescrit cette substance ;
            // « valable un mois » (art. 47, al. 2 OCStup) ne vise que le formulaire officiel (décision de Jim, 01.11.2026)
            'ordonnance_id' => [
                Rule::requiredIf(fn (): bool => $this->remiseSurOrdonnance()),
                Rule::prohibitedIf(fn (): bool => ! $this->remiseSurOrdonnance()),
                'nullable', 'integer',
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
            // Remise du tableau a : le numéro du formulaire officiel numéroté (art. 50, al. 2 OCStup)
            'ordonnance_officielle' => [
                Rule::requiredIf(fn (): bool => $this->remiseSurFormulaire()),
                Rule::prohibitedIf(fn (): bool => ! $this->remiseSurFormulaire()),
                'nullable', 'string', 'regex:/^(?!0+$)\d{6,10}$/',
            ],
            // Sa date : valable un mois (art. 47, al. 2 OCStup ; décision de Jim, 01.11.2026)
            'ordonnance_officielle_le' => [
                'bail',
                Rule::requiredIf(fn (): bool => $this->remiseSurFormulaire()),
                Rule::prohibitedIf(fn (): bool => ! $this->remiseSurFormulaire()),
                'nullable', 'date_format:Y-m-d',
                function (string $attribut, mixed $valeur, \Closure $echec): void {
                    $aujourdhui = aujourdhui();
                    if ((string) $valeur > $aujourdhui->toDateString()) {
                        $echec('La date du formulaire ne peut pas être dans le futur.');
                    } elseif ((string) $valeur < $aujourdhui->copy()->subMonthNoOverflow()->toDateString()) {
                        $echec('Ce formulaire officiel a plus d’un mois : il n’est plus valable (art. 47, al. 2 OCStup).');
                    }
                },
            ],
            'fournisseur' => [Rule::requiredIf(fn (): bool => $this->est($t::Reception, $t::Retour)), Rule::prohibitedIf(fn (): bool => ! $this->est($t::Reception, $t::Retour)), 'nullable', 'string', 'max:255'],
            // Chaque achat attesté par un bulletin de livraison, son numéro et sa date (art. 62, al. 1 OCStup) ; un retour
            // ou une élimination peut porter le sien (art. 62, al. 2)
            'bulletin' => [Rule::requiredIf(fn (): bool => $this->est($t::Reception)), 'required_with:bulletin_le', Rule::prohibitedIf(fn (): bool => ! $this->est($t::Reception, $t::Retour, $t::Elimination)), 'nullable', 'string', 'max:60'],
            // La date du bulletin date la ligne d'une réception (art. 57, al. 3 OCStup ; RegistreStupefiants::ecrire) ;
            // jamais dans le futur, à l'heure de la clinique
            'bulletin_le' => [
                'bail', Rule::requiredIf(fn (): bool => $this->est($t::Reception)), Rule::prohibitedIf(fn (): bool => ! $this->est($t::Reception, $t::Retour, $t::Elimination)), 'nullable', 'date_format:Y-m-d',
                function (string $attribut, mixed $valeur, \Closure $echec): void {
                    if ((string) $valeur > aujourdhui()->toDateString()) {
                        $echec('La date du bulletin ne peut pas être dans le futur.');
                    }
                },
            ],
            'corrige_id' => [Rule::requiredIf(fn (): bool => $this->est($t::Correction)), 'nullable', 'integer'],
            'motif' => [Rule::requiredIf(fn (): bool => $this->est($t::Perte, $t::Vol, $t::Elimination, $t::Correction)), 'nullable', 'string', 'max:255'],
            // Une réception donne son lot, son numéro et sa péremption (décision de Jim, 07.11.2026) : le lot est
            // créé, ou retrouvé au même numéro et à la même péremption, dans la clinique
            'numero_lot' => [Rule::requiredIf(fn (): bool => $this->est($t::Reception)), Rule::prohibitedIf(fn (): bool => ! $this->est($t::Reception)), 'nullable', 'string', 'max:30'],
            'lot_peremption' => [Rule::requiredIf(fn (): bool => $this->est($t::Reception)), Rule::prohibitedIf(fn (): bool => ! $this->est($t::Reception)), 'nullable', 'date_format:Y-m'],
            // Un lot déjà périmé s'inscrit, mais confirmé (07.11.2026)
            'lot_perime_confirme' => ['sometimes', 'boolean'],
            // Le lot d'une sortie, s'il est choisi (04.11.2026) : un lot de la substance, dans la clinique ; sans lui,
            // celui qui périme le premier est inscrit. Une correction reprend celui de sa ligne
            'lot_id' => [
                Rule::prohibitedIf(fn (): bool => $this->type()?->sens() !== -1),
                'nullable', 'integer',
                // Ce qu'il contient se compte en ml, sous verrou, à l'écriture (un lot vide y est refusé)
                Rule::exists('lots', 'id')->where('produit_id', (int) $this->input('produit_id'))
                    ->where('clinique_id', app(ContexteClinique::class)->cliniqueId()),
            ],
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
            'ordonnance_id.prohibited' => 'Seule la remise d’une substance du tableau b se fait sur une ordonnance de l’app ; au tableau a, le formulaire officiel.',
            'ordonnance_officielle.required' => 'Indiquez le numéro du formulaire officiel d’ordonnance (tableau a).',
            'ordonnance_officielle.regex' => 'Le numéro du formulaire officiel a de 6 à 10 chiffres, par exemple 7 012 345.',
            'ordonnance_officielle.prohibited' => 'Le formulaire officiel ne sert qu’à la remise d’une substance du tableau a.',
            'ordonnance_officielle_le.required' => 'Indiquez la date du formulaire officiel.',
            'ordonnance_officielle_le.date_format' => 'Indiquez la date du formulaire officiel.',
            'ordonnance_officielle_le.prohibited' => 'Le formulaire officiel ne sert qu’à la remise d’une substance du tableau a.',
            'fournisseur.required' => 'Indiquez le fournisseur.',
            'corrige_id.required' => 'Choisissez la ligne à corriger.',
            'numero_lot.required' => 'Indiquez le numéro du lot reçu.',
            'numero_lot.prohibited' => 'Le numéro de lot se donne à une réception ; une sortie choisit son lot.',
            'lot_peremption.required' => 'Indiquez la péremption du lot reçu.',
            'lot_peremption.date_format' => 'Indiquez la péremption du lot, mois et année.',
            'lot_peremption.prohibited' => 'La péremption du lot se donne à une réception.',
            'lot_id.exists' => 'Choisissez un lot de cette substance, dans la clinique.',
            'lot_id.prohibited' => 'Le lot se choisit pour une sortie ; une correction reprend celui de la ligne corrigée.',
            'fournisseur.prohibited' => 'Le fournisseur ne se donne qu’à une réception ou un retour.',
            'bulletin.prohibited' => 'Le bulletin ne se donne qu’à une réception, un retour ou une élimination.',
            'bulletin_le.prohibited' => 'Le bulletin ne se donne qu’à une réception, un retour ou une élimination.',
            'bulletin_le.date_format' => 'Indiquez la date du bulletin.',
            'bulletin_le.required' => 'Indiquez la date du bulletin.',
            'bulletin.required' => 'Indiquez le numéro du bulletin de livraison.',
            'bulletin.required_with' => 'Indiquez le numéro du bulletin, avec sa date.',
            'motif.required' => match ($this->type()) {
                TypeMouvementStupefiant::Elimination => 'Indiquez comment la substance a été éliminée (par exemple l’envoi recommandé au pharmacien cantonal).',
                TypeMouvementStupefiant::Correction => 'Indiquez pourquoi vous corrigez.',
                default => 'Décrivez ce qui s’est passé.',
            },
        ];
    }
}
