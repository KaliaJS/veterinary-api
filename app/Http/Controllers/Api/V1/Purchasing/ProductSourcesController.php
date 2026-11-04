<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Purchasing\SaveProductSourcesRequest;
use App\Http\Resources\V1\ProduitResource;
use App\Models\ProductOrderSetting;
use App\Models\ProductSource;
use App\Models\Produit;
use Illuminate\Support\Facades\DB;

/**
 * La commande d'un article (03.11.2026) : « Commander automatiquement sous
 * le seuil », jusqu'où remonter, et ses sources dans la clinique ouverte,
 * rangées par préférence. Les sources d'une clinique sœur ne bougent pas.
 */
class ProductSourcesController extends Controller
{
    public function update(SaveProductSourcesRequest $request, Produit $produit): ProduitResource
    {
        $this->authorize('update', $produit);

        DB::transaction(fn () => self::save($produit, $request->validated()));

        return new ProduitResource($produit->refresh()->load(['stocks' => fn ($requete) => $requete->withoutGlobalScope('clinique'), 'vetpoint']));
    }

    /**
     * Enregistre le réglage et remplace les sources de la clinique ouverte, dans l'ordre reçu.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(Produit $produit, array $data): void
    {
        $cliniqueId = (int) app(ContexteClinique::class)->cliniqueId();
        // L'automatique et jusqu'où remonter : dans la clinique ouverte seulement (04.11.2026)
        $setting = array_intersect_key($data, array_flip(['is_auto_order', 'reorder_up_to']));
        if (! empty($data['clear_exclusion'])) {
            $setting += ['excluded_at' => null, 'excluded_reason' => null, 'excluded_by' => null];
        }
        if ($setting !== []) {
            ProductOrderSetting::query()->updateOrCreate(['produit_id' => $produit->id, 'clinique_id' => $cliniqueId], $setting);
            $produit->unsetRelation('orderSettings');
        }
        if (! array_key_exists('sources', $data)) {
            return;
        }
        $ici = $produit->sourcesIn($cliniqueId)->pluck('id');
        ProductSource::query()->whereIn('id', $ici)->delete();
        foreach (array_values($data['sources']) as $i => $source) {
            $produit->sources()->create([
                'supplier_id' => $source['supplier_id'],
                'rank' => $i + 1,
                'reference' => $source['reference'] ?? null,
                'cost' => $source['cost'] ?? null,
            ]);
        }
    }
}
