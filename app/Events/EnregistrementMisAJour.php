<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** L'avancement d'un enregistrement de consultation (28.10.2026) : numéros et état seulement ; l'écran relit l'enregistrement par l'API. */
class EnregistrementMisAJour implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /** @param  array<string, mixed>  $donnees */
    public function __construct(public string $canal, public array $donnees) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->canal)];
    }

    public function broadcastAs(): string
    {
        return 'enregistrement.mis-a-jour';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->donnees;
    }
}
