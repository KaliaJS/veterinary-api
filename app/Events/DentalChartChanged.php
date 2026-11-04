<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Le schéma dentaire d'un patient a changé (04.11.2026) : une fenêtre
 * ouverte sur lui, dans n'importe quelle clinique de l'organisation (le
 * dossier est partagé), relit le relevé. Le message ne porte que le numéro
 * du patient.
 */
class DentalChartChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /** @param list<int> $cliniqueIds */
    public function __construct(public int $patientId, public array $cliniqueIds) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('clinique.'.$id), $this->cliniqueIds);
    }

    public function broadcastAs(): string
    {
        return 'releve-dentaire.modifie';
    }

    /** @return array{patient_id: int} */
    public function broadcastWith(): array
    {
        return ['patient_id' => $this->patientId];
    }
}
