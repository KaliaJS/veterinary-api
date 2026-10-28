<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Une suggestion de diagnostic rendue ou en échec (28.10.2026) : numéros et état seulement ; l'écran la relit par l'API. */
class DiagnosticMisAJour implements ShouldBroadcastNow
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
        return 'diagnostic.mis-a-jour';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->donnees;
    }
}
