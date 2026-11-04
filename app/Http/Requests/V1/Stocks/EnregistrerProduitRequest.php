<?php

namespace App\Http\Requests\V1\Stocks;

use App\Auth\ContexteClinique;
use App\Enums\CategorieProduit;
use App\Enums\VatRate;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Http\Requests\V1\Purchasing\SaveProductSourcesRequest;
use App\Http\Requests\V1\Stocks\Concerns\ChampPeremption;
use App\Support\AtcCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EnregistrerProduitRequest extends FormRequest
{
    use ChampPeremption;
    use ReglesScopees;

    /** Le code ATC s'écrit sans espace, en capitales (« qm01ac06 » devient « QM01AC06 ») */
    protected function prepareForValidation(): void
    {
        if ($this->has('atc_code')) {
            $this->merge(['atc_code' => AtcCode::normalize($this->input('atc_code'))]);
        }
    }

    public function peremptionValidee(): ?CarbonImmutable
    {
        return $this->peremptionLe($this->validated('peremption'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'categorie' => ['required', Rule::enum(CategorieProduit::class)],
            'prix' => ['required', 'numeric', 'min:0', 'max:99999'],
            // Le taux de TVA à la vente (03.11.2026) ; sans lui, celui de la famille
            'vat_rate' => ['sometimes', Rule::enum(VatRate::class)],
            'cout' => ['sometimes', 'numeric', 'min:0', 'max:99999'],
            'unite' => ['sometimes', 'string', 'max:30'],
            'seuil_alerte' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'est_boutique' => ['sometimes', 'boolean'],
            'est_sur_ordonnance' => ['sometimes', 'boolean'],
            'peremption' => $this->reglesPeremption(),
            'fournisseur' => ['nullable', 'string', 'max:255'],
            'ean' => ['nullable', 'string', 'max:20'],
            // Code ATC ou ATCvet (03.11.2026) : la famille du médicament, pour la mise en garde des allergies
            'atc_code' => ['nullable', 'string', 'max:12', 'regex:'.AtcCode::PATTERN],
            'quantite_initiale' => ['sometimes', 'integer', 'min:0', 'max:99999'],
            // Clinique qui reçoit le stock initial (celle du jeton sinon).
            'clinique_id' => ['sometimes', 'nullable', 'integer', $this->estUneCliniqueDeLOrganisation()],
            // Nouvel article B (03.11.2026) : le lot du stock du jour et sa péremption (AAAA-MM)
            'lot' => ['nullable', 'string', 'max:40'],
            'lot_peremption' => ['nullable', 'date_format:Y-m'],
            // Vente : promotion et mise en avant, seulement en boutique
            'promo_pct' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'est_vedette' => ['sometimes', 'boolean'],
            // Soins : stupéfiant et son tableau, contenance (médicaments)
            'est_stupefiant' => ['sometimes', 'boolean'],
            'tableau_stupefiant' => [Rule::requiredIf(fn (): bool => $this->isNarcotic()), 'nullable', Rule::in(['a', 'b'])],
            'content_per_unit' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'dose_unit' => [Rule::requiredIf(fn (): bool => $this->category() === CategorieProduit::Medicament && $this->filled('content_per_unit')), 'nullable', 'string', 'max:20'],
            // Commande : automatique sous le seuil, jusqu'où, ses sources dans la clinique ouverte
            ...SaveProductSourcesRequest::sourceRules(),
        ];
    }

    /** La famille demandée, si elle est connue */
    public function category(): ?CategorieProduit
    {
        return CategorieProduit::tryFrom((string) $this->input('categorie'));
    }

    /** Un stupéfiant : seulement parmi les médicaments et antiparasitaires */
    public function isNarcotic(): bool
    {
        return $this->boolean('est_stupefiant') && (bool) $this->category()?->isDrug();
    }

    /** Le premier jour du mois de péremption du lot du jour */
    public function lotExpiry(): ?CarbonImmutable
    {
        $value = $this->validated('lot_peremption');

        return $value ? CarbonImmutable::createFromFormat('!Y-m', $value) : null;
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Un stupéfiant n'entre au stock que par son registre (24.10.2026)
            if ($this->isNarcotic() && (int) $this->input('quantite_initiale', 0) > 0) {
                $validator->errors()->add('quantite_initiale', 'Un stupéfiant entre au stock par son registre : inscrivez sa réception au registre des stupéfiants.');
            }
            // Le lot du jour : sa péremption, sauf pour le matériel et les accessoires ; jamais déjà passée (04.11.2026)
            $category = $this->category();
            if ($this->filled('lot') && ! $this->filled('lot_peremption') && (int) $this->input('quantite_initiale', 0) > 0
                && ! in_array($category, [CategorieProduit::Materiel, CategorieProduit::Accessoire], true)) {
                $validator->errors()->add('lot_peremption', 'Donnez la péremption de ce lot.');
            }
            $expiry = is_string($this->input('lot_peremption')) && preg_match('/^\d{4}-\d{2}$/', $this->input('lot_peremption')) === 1 ? $this->input('lot_peremption') : null;
            $thisMonth = now(app(ContexteClinique::class)->clinique()?->fuseau_horaire ?: 'Europe/Zurich')->format('Y-m');
            if ($expiry !== null && $expiry < $thisMonth) {
                $validator->errors()->add('lot_peremption', 'La péremption de ce lot est déjà passée.');
            }
            // Remonter jusqu'à moins que le seuil n'a pas de sens
            if ($this->boolean('is_auto_order') && $this->filled('reorder_up_to') && (int) $this->input('reorder_up_to') < (int) $this->input('seuil_alerte', 0)) {
                $validator->errors()->add('reorder_up_to', 'Remonter au moins jusqu’au seuil d’alerte.');
            }
        }];
    }

    public function cliniqueId(): ?int
    {
        return $this->filled('clinique_id') ? (int) $this->validated('clinique_id') : null;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...AtcCode::messages(),
            ...SaveProductSourcesRequest::sourceMessages(),
            'tableau_stupefiant.required' => 'Choisissez le tableau : a ou b.',
            'dose_unit.required' => 'L’unité de dose manque : ml, cp, UI…',
            'lot_peremption.date_format' => 'Donnez la péremption du lot : mois et année.',
            'promo_pct.max' => 'Une promotion va jusqu’à 90 %.',
        ];
    }
}
