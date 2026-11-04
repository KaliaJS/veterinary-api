<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Enums\StatutCommandeFournisseur;
use App\Http\Controllers\Api\V1\CommandeFournisseurController;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CommandeFournisseurResource;
use App\Models\CommandeFournisseur;
use App\Services\Purchasing\AutoBaskets;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le panier automatique dans Commandes (03.11.2026) : le mettre en pause (il
 * ne part plus seul), le reprendre, attendre la date suivante quand il est
 * sous le minimum, ou l'envoyer maintenant par le canal de son fournisseur.
 * Le panier de la clinique ouverte seulement (404 ailleurs).
 */
class AutoBasketController extends Controller
{
    public function pause(int $commande): CommandeFournisseurResource
    {
        return $this->change($commande, fn (CommandeFournisseur $basket) => $basket->forceFill(['paused_at' => now()])->save());
    }

    public function resume(int $commande): CommandeFournisseurResource
    {
        return $this->change($commande, fn (CommandeFournisseur $basket) => $basket->forceFill(['paused_at' => null])->save());
    }

    /** Sous le minimum à la date butoir : il attend la date suivante. */
    public function wait(int $commande): CommandeFournisseurResource
    {
        return $this->change($commande, function (CommandeFournisseur $basket): void {
            $supplier = $basket->supplier()->first();
            $basket->forceFill([
                'deadline_at' => $supplier?->nextDeadline(CarbonImmutable::now()),
                'decision_asked_for' => null,
                'eve_notice_for' => null,
            ])->save();
        });
    }

    /**
     * Envoyer maintenant, confirmé à l'écran : chez une centrale avec
     * l'adresse, la livraison et la date choisies ; par e-mail ; à la main.
     */
    public function sendNow(Request $request, int $commande, AutoBaskets $baskets): CommandeFournisseurResource
    {
        $basket = CommandeFournisseurController::commandeDeLaClinique($commande);
        $this->authorize('update', $basket);
        self::requireOpenBasket($basket);
        $choice = $request->validate([
            'adresse_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'livraison' => ['sometimes', 'in:standard,express'],
            'date_souhaitee' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ], [
            'livraison.in' => 'Choisissez une livraison standard ou express.',
            'date_souhaitee.date_format' => 'Donnez la date souhaitée au format AAAA-MM-JJ.',
        ]);
        // Un envoi resté sans réponse chez la centrale : il se tranche d'abord (connecteur générique)
        $pending = $baskets->pendingMessage($basket);
        if ($pending !== null) {
            throw ValidationException::withMessages(['statut' => $pending]);
        }
        if ($basket->lignes()->doesntExist()) {
            throw ValidationException::withMessages(['lignes' => 'Ajoutez au moins une ligne avant d’envoyer.']);
        }

        $sent = $baskets->sendNow($basket, $choice);
        if ($sent === null) {
            throw ValidationException::withMessages(['lignes' => 'Tous ses articles sont passés chez un autre fournisseur : le panier est vide.']);
        }

        return new CommandeFournisseurResource($sent->load('lignes'));
    }

    /** Les adresses du compte de la clinique chez la centrale du panier, pour « Envoyer maintenant » */
    public function addresses(int $commande, AutoBaskets $baskets): JsonResponse
    {
        $basket = CommandeFournisseurController::commandeDeLaClinique($commande);
        $this->authorize('update', $basket);

        return response()->json(['data' => $baskets->addresses($basket)]);
    }

    /** @param  \Closure(CommandeFournisseur): mixed  $change */
    private function change(int $id, \Closure $change): CommandeFournisseurResource
    {
        $basket = CommandeFournisseurController::commandeDeLaClinique($id);
        $this->authorize('update', $basket);

        $basket = DB::transaction(function () use ($basket, $change): CommandeFournisseur {
            $locked = CommandeFournisseur::query()->whereKey($basket->id)->lockForUpdate()->firstOrFail();
            self::requireOpenBasket($locked);
            $change($locked);

            return $locked;
        });

        return new CommandeFournisseurResource($basket->load('lignes'));
    }

    private static function requireOpenBasket(CommandeFournisseur $basket): void
    {
        if (! $basket->is_auto || $basket->statut !== StatutCommandeFournisseur::Brouillon) {
            throw ValidationException::withMessages(['statut' => 'Seul un panier automatique pas encore parti se règle ainsi.']);
        }
    }
}
