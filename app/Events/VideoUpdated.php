<?php

namespace App\Events;

use App\Models\Video;
use App\Support\TempsReel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Une vidéo du vendeur a changé hors de son écran (31.10.2026) : relue,
 * mise en vente d'elle-même, retirée ou refusée par la relecture. Sa page
 * « Vendre » se met à jour sans être rechargée.
 */
class VideoUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /** @param  array<string, mixed>  $data */
    public function __construct(public string $channel, public array $data) {}

    /** Le canal du vendeur : le compte du vétérinaire, ou les vidéos de l'organisation. */
    /** @param  string|null  $notice  ce que la relecture a refusé sans le vendeur (mise en vente qui n'a pas pu se faire) */
    public static function announce(Video $video, ?string $notice = null): void
    {
        $channel = $video->seller_user_id !== null ? 'utilisateur.'.$video->seller_user_id : 'organisation.'.$video->organisation_id.'.videos';
        $data = [
            'id' => $video->uuid,
            'status' => $video->status->value,
            'files_status' => $video->files_status->value,
            'publish_requested' => $video->publish_requested_at !== null,
            'rejection_reason' => $video->rejection_reason,
            'notice' => $notice,
        ];
        TempsReel::diffuser(fn () => self::dispatch($channel, $data));
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'video.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->data;
    }
}
