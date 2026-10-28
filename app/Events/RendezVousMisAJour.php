<?php

namespace App\Events;

use App\Http\Resources\V1\RendezVousResource;
use App\Models\RendezVous;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un rendez-vous pris, déplacé, annulé ou modifié (28.10.2026) : diffusé
 * tout de suite sur le canal de sa clinique ; les autres postes le voient
 * sans relire.
 */
class RendezVousMisAJour implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public RendezVous $rendezVous) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clinique.'.$this->rendezVous->clinique_id.'.planning')];
    }

    public function broadcastAs(): string
    {
        return 'rendez-vous.mis-a-jour';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return json_decode((string) json_encode((new RendezVousResource($this->rendezVous))->resolve()), true);
    }
}
