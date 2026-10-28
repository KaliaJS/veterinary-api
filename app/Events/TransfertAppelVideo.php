<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un appel vidéo transféré à un collègue (28.10.2026) : le transfert
 * demandé ou annulé, sur le canal du compte du collègue.
 */
class TransfertAppelVideo implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /** @param  array{etat: string, session_id: int, patient: ?string, de: ?string}  $donnees */
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

    /** @return array{etat: string, session_id: int, patient: ?string, de: ?string} */
    public function broadcastWith(): array
    {
        return $this->donnees;
    }
}
