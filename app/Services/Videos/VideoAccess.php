<?php

namespace App\Services\Videos;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;

/**
 * Ce qu'une personne peut regarder d'une vidéo (31.10.2026) : en entier si
 * elle l'a achetée (à vie, même retirée de la vente), si elle en est le
 * vendeur, ou par Plus quand la vidéo y est incluse ; sinon la première
 * minute, si la vidéo est en vente et lui est destinée.
 */
class VideoAccess
{
    /** 'seller', 'owned', 'plus', 'preview', ou null (rien à voir). */
    public function for(Video $video, User $user, bool $isSeller = false): ?string
    {
        if ($isSeller) {
            return 'seller';
        }
        if ($video->isOwnedBy($user)) {
            return 'owned';
        }
        if ($video->status !== VideoStatus::Published || ! $video->isForAudienceOf($user)) {
            return null;
        }
        if ($video->in_plus && $video->audience?->includesOwners() && $user->aPlus()) {
            return 'plus';
        }

        return 'preview';
    }
}
