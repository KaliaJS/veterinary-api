<?php

namespace App\Http\Requests\V1\Purchasing;

use App\Auth\ContexteClinique;
use App\Models\Produit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La commande d'un article dans la clinique (03.11.2026) : commander
 * automatiquement sous le seuil, jusqu'où remonter, et ses sources par
 * préférence (des fournisseurs de la clinique ouverte, chacun une fois).
 */
class SaveProductSourcesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public static function sourceRules(): array
    {
        return [
            'is_auto_order' => ['sometimes', 'boolean'],
            // « Reprendre la commande automatique » : lever à la main l'écart décidé par la clinique
            'clear_exclusion' => ['sometimes', 'boolean'],
            'reorder_up_to' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'sources' => ['sometimes', 'array', 'max:10'],
            'sources.*.supplier_id' => ['required', 'integer', 'distinct', Rule::exists('suppliers', 'id')->where('clinique_id', app(ContexteClinique::class)->cliniqueId())],
            'sources.*.reference' => ['nullable', 'string', 'max:60'],
            'sources.*.cost' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ];
    }

    /** @return array<string, string> */
    public static function sourceMessages(): array
    {
        return [
            'sources.*.supplier_id.exists' => 'Ce fournisseur n’est pas de cette clinique.',
            'sources.*.supplier_id.distinct' => 'Ce fournisseur est déjà une source de l’article.',
            'reorder_up_to.min' => 'Remonter au moins à 1.',
            'reorder_up_to.gte' => 'Remonter au moins jusqu’au seuil d’alerte.',
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $produit = $this->route('produit');
        $rules = self::sourceRules();
        // Remonter jusqu'à moins que le seuil n'a pas de sens
        if ($produit instanceof Produit && $this->filled('reorder_up_to')) {
            $rules['reorder_up_to'][] = 'gte:'.(int) $produit->seuil_alerte;
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return self::sourceMessages();
    }
}
