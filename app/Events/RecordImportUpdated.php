<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * La découpe d'un gros PDF reçu a changé (09.11.2026) : proposée, ou en
 * échec. Le poste qui attend la relit par l'API ; le message ne porte que
 * l'identifiant.
 */
class RecordImportUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public int $importId, public int $cliniqueId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clinique.'.$this->cliniqueId)];
    }

    public function broadcastAs(): string
    {
        return 'record-import.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->importId];
    }
}
