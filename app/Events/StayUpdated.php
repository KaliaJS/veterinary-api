<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un séjour d'hospitalisation a changé (03.11.2026) : un soin, une
 * douleur, une observation, un traitement, le rapport de la journée. Les
 * autres postes de la clinique le relisent par l'API, derrière leurs
 * droits ; le message ne porte que l'identifiant (règle de Jim : temps réel
 * par Reverb, jamais de relecture régulière).
 */
class StayUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public int $stayId, public int $cliniqueId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clinique.'.$this->cliniqueId)];
    }

    public function broadcastAs(): string
    {
        return 'sejour.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->stayId];
    }
}
