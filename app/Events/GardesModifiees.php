<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Les nuits de garde d'une clinique ont pu changer (un choix, une absence
 * qui fait passer son tour à un vétérinaire ; 11.11.2026) : le Planning de
 * l'Équipe et le badge « De garde » de l'agenda les relisent. Rien d'autre
 * ne part : le détail reste derrière les droits de l'API.
 */
class GardesModifiees implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public int $cliniqueId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clinique.'.$this->cliniqueId.'.planning')];
    }

    public function broadcastAs(): string
    {
        return 'gardes.modifiees';
    }

    /** @return array<string, never> */
    public function broadcastWith(): array
    {
        return [];
    }
}
