<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un appel vidéo transféré à un collègue (28.10.2026), sur le canal du
 * compte : le transfert demandé, annulé ou terminé pendant l'attente (chez
 * le collègue), puis pris (chez celui qui a transféré, et sur les autres
 * appareils du collègue).
 */
class TransfertAppelVideo implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /** @param  array<string, mixed>  $donnees */
    public function __construct(public int $userId, public array $donnees) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('utilisateur.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'appel.transfert';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->donnees;
    }
}
