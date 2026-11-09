<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Une invitation de lettre de référence a changé (09.11.2026) : reçue,
 * acceptée, refusée, coupée. Le destinataire (le vétérinaire, ou sa
 * clinique) relit ses invitations par l'API ; le message ne porte que
 * l'identifiant.
 */
class ReferralInvitationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /** @param  list<string>  $channels */
    public function __construct(public int $invitationId, public array $channels) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (string $c) => new PrivateChannel($c), $this->channels);
    }

    public function broadcastAs(): string
    {
        return 'referral-invitation.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->invitationId];
    }
}
