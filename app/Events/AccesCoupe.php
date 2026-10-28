<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * L'accès d'une personne à des cliniques est coupé (suspension, retrait,
 * fin de contrat), décision de Jim du 28.10.2026 : ses écrans ouverts
 * l'apprennent aussitôt sur son canal et reviennent à la connexion. Ses
 * jetons sont déjà révoqués : la requête suivante serait refusée.
 */
class AccesCoupe implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /** @param  list<int>  $cliniques */
    public function __construct(public int $userId, public array $cliniques) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('utilisateur.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'acces.coupe';
    }

    /** @return array{cliniques: list<int>} */
    public function broadcastWith(): array
    {
        return ['cliniques' => $this->cliniques];
    }
}
