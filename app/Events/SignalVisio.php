<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un message de mise en relation WebRTC (offre, réponse, candidat, prêt,
 * fin ; 28.10.2026), sur le canal de la session. Un gros message part par
 * référence : Reverb refuse plus de 10 Ko, le destinataire le relit par l'API.
 */
class SignalVisio implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /** @param  array<string, mixed>|null  $donnees */
    public function __construct(
        public int $sessionId,
        public string $de,
        public string $type,
        public ?array $donnees,
        public ?string $reference = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('visio.'.$this->sessionId)];
    }

    public function broadcastAs(): string
    {
        return 'signal';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return array_filter([
            'de' => $this->de,
            'type' => $this->type,
            'donnees' => $this->reference === null ? ($this->donnees ?? []) : null,
            'reference' => $this->reference,
        ], fn ($valeur): bool => $valeur !== null);
    }
}
