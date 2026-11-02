<?php

namespace App\Events;

use App\Models\Intervention;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Une intervention a changé hors de l'écran du bloc (02.11.2026) : le
 * propriétaire a signé le consentement sur son téléphone, par exemple.
 * Diffusé sur le canal de la clinique ; court, sans données personnelles,
 * l'intervention se relit par l'API derrière ses droits.
 */
class InterventionUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Intervention $intervention) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clinique.'.$this->intervention->clinique_id)];
    }

    public function broadcastAs(): string
    {
        return 'intervention.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->intervention->id];
    }
}
