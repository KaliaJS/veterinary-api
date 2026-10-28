<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ce qui fait naître les alertes d'une clinique a changé (stock, lots,
 * seuil, paiement, consommation, jour nouveau ; 28.10.2026) : elle relit
 * ses alertes, filtrées par les droits de chacun. Le message ne dit rien de
 * plus.
 */
class AlertesModifiees implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public int $cliniqueId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clinique.'.$this->cliniqueId)];
    }

    public function broadcastAs(): string
    {
        return 'alertes.modifiees';
    }

    /** @return array<string, never> */
    public function broadcastWith(): array
    {
        return [];
    }
}
