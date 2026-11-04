<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Auth\ContexteClinique;
use App\Enums\StatutCommandeFournisseur;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Purchasing\SaveSupplierRequest;
use App\Http\Resources\V1\SupplierResource;
use App\Models\CommandeFournisseur;
use App\Models\Supplier;
use App\Services\Purchasing\PurchasingConnectors;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Réglages, Fournisseurs (03.11.2026, droit Stocks) : les fournisseurs et
 * centrales d'achat de la clinique ouverte ; ceux d'une autre clinique
 * n'existent pas d'ici (404). Les centrales qu'on peut choisir viennent de
 * la configuration, jamais d'une liste écrite dans l'écran.
 */
class SupplierController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SupplierResource::collection(Supplier::query()->orderBy('name')->get());
    }

    /** Les centrales d'achat connues : leur clé, leur nom, reliées ou non à la clinique. */
    public function connectors(PurchasingConnectors $connectors): JsonResponse
    {
        $cliniqueId = (int) app(ContexteClinique::class)->cliniqueId();

        return response()->json(['data' => collect($connectors->keys())->map(function (string $key) use ($connectors, $cliniqueId): array {
            $connector = $connectors->get($key);

            // `sends_alone` : faux tant que l'envoi automatique par les centrales est coupé ; le panier attend alors qu'on l'envoie
            return ['key' => $key, 'name' => $connector?->name() ?? $key, 'is_linked' => (bool) $connector?->isLinked($cliniqueId), 'sends_alone' => (bool) config('purchasing.auto_send_api')];
        })->values()]);
    }

    public function store(SaveSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create(self::attributes($request->validated()));

        return (new SupplierResource($supplier))->response()->setStatusCode(201);
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $supplier->update(self::attributes($request->validated(), $supplier));

        // Jours ou heure changés : les paniers automatiques visent la nouvelle date butoir
        if ($supplier->wasChanged(['order_days', 'order_time'])) {
            CommandeFournisseur::query()->where('supplier_id', $supplier->id)->where('is_auto', true)
                ->where('statut', StatutCommandeFournisseur::Brouillon)
                ->update(['deadline_at' => $supplier->nextDeadline(CarbonImmutable::now()), 'eve_notice_for' => null, 'decision_asked_for' => null]);
        }

        return new SupplierResource($supplier);
    }

    /**
     * Retirer un fournisseur (04.11.2026) : son panier automatique ouvert
     * devient un brouillon ordinaire, à envoyer en PDF ; ses articles ne
     * comptent plus comme en commande pour la commande automatique, qui les
     * passe à leur source suivante au prochain passage.
     */
    public function destroy(Supplier $supplier): Response
    {
        DB::transaction(function () use ($supplier): void {
            // Le fournisseur d'abord, puis ses paniers : le même ordre que
            // l'ajout au panier (AutoOrdering::addToBasket), sans quoi les
            // deux peuvent s'attendre l'un l'autre. Pas d'essai automatique de
            // cet ordre : sous SQLite (les essais), lockForUpdate ne verrouille rien
            Supplier::query()->whereKey($supplier->id)->lockForUpdate()->first();
            CommandeFournisseur::query()->where('supplier_id', $supplier->id)->where('is_auto', true)
                ->where('statut', StatutCommandeFournisseur::Brouillon)
                ->update(['is_auto' => false, 'supplier_removed_at' => now(), 'paused_at' => null, 'decision_asked_for' => null, 'eve_notice_for' => null]);
            $supplier->delete();
        });

        return response()->noContent();
    }

    /**
     * Ce qui s'enregistre : la centrale seulement pour le canal « api ».
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data, ?Supplier $supplier = null): array
    {
        $channel = $data['channel'] ?? ($supplier !== null ? $supplier->channel : 'manual');
        if ($channel !== 'api') {
            $data['connector'] = null;
        }
        if (array_key_exists('order_days', $data)) {
            $days = array_map('intval', $data['order_days'] ?? []);
            sort($days);
            $data['order_days'] = $days;
        }

        return $data;
    }
}
