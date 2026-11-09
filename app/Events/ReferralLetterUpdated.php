<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Une lettre de référence a changé (09.11.2026) : son brouillon écrit, une
 * dictée prise, la signature, l'envoi. Les autres postes de la clinique la
 * relisent par l'API ; le message ne porte que l'identifiant (règle de Jim :
 * temps réel par Reverb, jamais de relecture régulière).
 */
class ReferralLetterUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public int $letterId, public int $cliniqueId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clinique.'.$this->cliniqueId)];
    }

    public function broadcastAs(): string
    {
        return 'referral-letter.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->letterId];
    }
}
