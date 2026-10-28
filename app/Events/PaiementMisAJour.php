<?php

namespace App\Events;

use App\Http\Resources\V1\PaiementResource;
use App\Models\Paiement;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * L'état d'un paiement au terminal ou par TWINT a changé (28.10.2026) :
 * diffusé tout de suite (sans file) sur le canal de la clinique ; la caisse
 * et la boutique l'attendent au lieu de relire.
 */
class PaiementMisAJour implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Paiement $paiement) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clinique.'.$this->paiement->clinique_id)];
    }

    public function broadcastAs(): string
    {
        return 'paiement.mis-a-jour';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $this->paiement->loadMissing(['terminal', 'facture.lignes', 'facture.prescriptions', 'facture.decompte']);

        return json_decode((string) json_encode((new PaiementResource($this->paiement))->resolve()), true);
    }
}
