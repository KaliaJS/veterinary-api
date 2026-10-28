<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Les messages d'un fil ont changé (28.10.2026) : la clinique
 * (`clinique.{id}`) ou le propriétaire (`utilisateur.{id}`) relit ses
 * fils. Aucun contenu dans l'annonce : le texte se lit par l'API.
 */
class MessagesChangent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public string $canal, public ?int $userId = null) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->canal)];
    }

    public function broadcastAs(): string
    {
        return 'messages.change';
    }

    /** @return array{user_id: ?int} */
    public function broadcastWith(): array
    {
        return ['user_id' => $this->userId];
    }
}
