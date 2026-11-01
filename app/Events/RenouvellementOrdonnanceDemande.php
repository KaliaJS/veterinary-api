<?php

namespace App\Events;

use App\Models\Ordonnance;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Le propriétaire demande le renouvellement d'une ordonnance depuis son
 * espace (01.11.2026) : diffusé tout de suite sur le canal de la clinique
 * qui l'a établie ; l'écran Ordonnances et la pastille du menu le voient
 * sans recharger. Court, sans données personnelles : l'ordonnance se
 * relit par l'API, derrière ses droits.
 */
class RenouvellementOrdonnanceDemande implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Ordonnance $ordonnance) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clinique.'.$this->ordonnance->clinique_id)];
    }

    public function broadcastAs(): string
    {
        return 'ordonnance.renouvellement-demande';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->ordonnance->id, 'numero' => $this->ordonnance->numero];
    }
}
