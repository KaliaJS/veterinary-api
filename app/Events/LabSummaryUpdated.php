<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Le résumé d'une analyse a changé (09.11.2026) : rédigé, signé, envoyé.
 * Les autres postes de la clinique le relisent ; le message ne porte que
 * l'identifiant de l'analyse.
 */
class LabSummaryUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public int $analyseId, public int $cliniqueId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clinique.'.$this->cliniqueId)];
    }

    public function broadcastAs(): string
    {
        return 'lab-summary.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->analyseId];
    }
}
