<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Une facture d'abonnement de l'organisation a changé (paiement vérifié, refusé ; 28.10.2026) : la page Facturation la relit. */
class FactureAbonnementMisAJour implements ShouldBroadcastNow
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
        return 'facture-abonnement.mis-a-jour';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->donnees;
    }
}
