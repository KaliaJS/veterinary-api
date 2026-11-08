<?php

namespace App\Http\Requests\V1\RendezVous;

use App\Enums\CategorieProduit;
use App\Enums\VatContext;
use App\Http\Requests\V1\Concerns\ReglesScopees;
use App\Http\Requests\V1\Concerns\RulesForIsabv;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fin de consultation (« Ma journée ») : actes cochés au catalogue,
 * vaccin injecté (inscrit au carnet), médicaments prescrits (ordonnance
 * au registre + sortie de stock). Vaccin et médicaments exigent une
 * fiche patient — contrôlé au contrôleur, le RDV pouvant être
 * dénormalisé.
 */
class FinConsultationRequest extends FormRequest
{
    use ReglesScopees;
    use RulesForIsabv;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prestations' => ['required', 'array', 'min:1'],
            'prestations.*' => ['required', $this->existeDansLOrganisation('prestations')],
            'vaccination' => ['sometimes', 'array'],
            'vaccination.nom' => ['required_with:vaccination', 'string', 'max:255'],
            'vaccination.marque' => ['nullable', 'string', 'max:255'],
            'vaccination.lot' => ['nullable', 'string', 'max:255'],
            'vaccination.echeance_le' => ['required_with:vaccination', 'date'],
            'vaccination.produit_id' => [
                'nullable',
                $this->existeDansLOrganisation('produits')->where('categorie', CategorieProduit::Medicament->value),
            ],
            'medicaments' => ['sometimes', 'array'],
            'medicaments.*.produit_id' => [
                'required',
                $this->existeDansLOrganisation('produits')->where('categorie', CategorieProduit::Medicament->value),
            ],
            'medicaments.*.posologie' => ['nullable', 'string', 'max:255'],
            'medicaments.*.duree' => ['nullable', 'string', 'max:255'],
            // Donné pendant la consultation ou remis au propriétaire ; sans lui, proposé selon l'article (TVA par ligne, 03.11.2026)
            'medicaments.*.vat_context' => ['sometimes', Rule::in([VatContext::Care->value, VatContext::Sale->value])],
            ...$this->isabvRules('medicaments'),
        ];
    }
}
