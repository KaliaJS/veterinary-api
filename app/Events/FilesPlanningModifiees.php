<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Les files du planning d'une clinique ont changé (urgences, demandes en
 * ligne, liste d'attente ; 28.10.2026) : le planning les relit une fois.
 * Rien d'autre ne part : le détail reste derrière les droits de l'API.
 */
class FilesPlanningModifiees implements ShouldBroadcastNow
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
        return 'files.modifiees';
    }

    /** @return array<string, never> */
    public function broadcastWith(): array
    {
        return [];
    }
}
