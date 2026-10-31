<?php

namespace App\Events;

use App\Models\Devis;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Le client a accepté ou refusé un devis dans son espace (31.10.2026) :
 * diffusé tout de suite sur le canal de la clinique, l'écran Devis le
 * relit au lieu d'attendre qu'on recharge. Court, sans données
 * personnelles : le devis se relit par l'API, derrière ses droits.
 */
class DevisMisAJour implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Devis $devis) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clinique.'.$this->devis->clinique_id)];
    }

    public function broadcastAs(): string
    {
        return 'devis.mis-a-jour';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->devis->id, 'numero' => $this->devis->numero, 'statut' => $this->devis->statut->value];
    }
}
