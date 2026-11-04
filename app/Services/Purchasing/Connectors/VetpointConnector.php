<?php

namespace App\Services\Purchasing\Connectors;

use App\Http\Controllers\Api\V1\Vetpoint\CommandeVetpointController;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\ConnexionVetpoint;
use App\Services\Purchasing\AutoBaskets;
use App\Services\Purchasing\PurchasingConnector;
use App\Services\Purchasing\PurchasingFailure;
use App\Services\Vetpoint\VetpointCgv;
use App\Services\Vetpoint\VetpointCleRefusee;
use App\Services\Vetpoint\VetpointIndisponible;
use App\Services\Vetpoint\VetpointLimite;
use App\Services\Vetpoint\VetpointOrderSender;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Vetpoint derrière l'interface des centrales (03.11.2026) : relié quand la
 * clinique a collé sa clé ; un article qui n'y est pas relié, ou que Vetpoint
 * dit indisponible, ne peut pas y partir ; le panier part à l'adresse par
 * défaut du compte Vetpoint de la clinique, en livraison standard, par le
 * même envoi que depuis l'écran (jamais deux fois).
 */
class VetpointConnector implements PurchasingConnector
{
    public function __construct(private VetpointOrderSender $sender) {}

    public function name(): string
    {
        return 'Vetpoint';
    }

    public function isLinked(int $clinicId): bool
    {
        $connexion = ConnexionVetpoint::withoutGlobalScopes()->where('clinique_id', $clinicId)->first();

        return $connexion !== null && $connexion->cleEnClair() !== null;
    }

    public function unavailable(CommandeFournisseur $order): array
    {
        $lines = $order->lignes()->with(['produit' => fn ($p) => $p->withoutGlobalScopes()->with('vetpoint')])->get();
        $out = [];
        try {
            $client = null;
            foreach ($lines as $line) {
                /** @var CommandeFournisseurLigne $line */
                $link = $line->produit?->vetpoint;
                if ($link === null) {
                    $out[$line->id] = 'Pas relié à Vetpoint';

                    continue;
                }
                $client ??= CommandeVetpointController::clientDe((int) $order->clinique_id);
                $product = $client->produit((string) $link->vetpoint_id);
                if ($product === null) {
                    $out[$line->id] = 'Plus au catalogue de Vetpoint';
                } elseif (($product['available'] ?? true) === false) {
                    $out[$line->id] = 'Indisponible chez Vetpoint';
                }
            }
        } catch (VetpointCleRefusee|VetpointCgv|VetpointLimite|VetpointIndisponible $e) {
            throw $this->failure($e);
        } catch (Throwable $e) {
            throw AutoBaskets::failureFrom($e);
        }

        return $out;
    }

    public function send(CommandeFournisseur $order, array $choice = []): void
    {
        try {
            $this->sender->send($order, [
                'adresse_id' => $choice['adresse_id'] ?? null,
                'livraison' => $choice['livraison'] ?? 'standard',
                'date_souhaitee' => $choice['date_souhaitee'] ?? null,
            ]);
        } catch (Throwable $e) {
            throw AutoBaskets::failureFrom($e);
        }
    }

    public function addresses(int $clinicId): array
    {
        try {
            return CommandeVetpointController::clientDe($clinicId)->adressesLivraison();
        } catch (VetpointCleRefusee|VetpointCgv|VetpointLimite|VetpointIndisponible $e) {
            throw $this->failure($e);
        } catch (Throwable $e) {
            throw AutoBaskets::failureFrom($e);
        }
    }

    public function pendingSend(CommandeFournisseur $order): ?string
    {
        try {
            CommandeVetpointController::exigerHorsEnvoiEnSuspens($order);

            return null;
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        }
    }

    private function failure(Throwable $e): PurchasingFailure
    {
        try {
            VetpointOrderSender::refuse($e);
        } catch (Throwable $http) {
            return new PurchasingFailure($http->getMessage(), 0, $e);
        }
    }
}
