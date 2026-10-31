<?php

namespace App\Services\Videos;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;

/**
 * Ce qu'une personne peut regarder d'une vidéo (31.10.2026) : en entier si
 * elle l'a achetée (à vie, même retirée de la vente), si elle en est le
 * vendeur, ou par Plus quand la vidéo y est incluse ; sinon la première
 * minute, si la vidéo est en vente et lui est destinée. Un épisode suit
 * sa série ; une série se lit comme une vidéo (achetée, Plus, aperçu).
 * Un visiteur sans compte (l'écran « Vidéos » ouvert depuis l'accueil du
 * site, 31.10.2026) : la première minute, ou « gratuite » ; jamais Plus.
 */
class VideoAccess
{
    /** 'seller', 'owned', 'free' (gratuite), 'plus', 'preview', ou null (rien à voir). */
    public function for(Video $video, ?User $user, bool $isSeller = false): ?string
    {
        if ($isSeller) {
            return 'seller';
        }
        if ($video->isEpisode()) {
            return $this->forEpisode($video, $user);
        }
        if ($user !== null && $video->isOwnedBy($user)) {
            return 'owned';
        }
        // Ni aperçu ni Plus d'un vendeur qui ne vend plus ; ceux qui ont acheté gardent leur vidéo
        if ($video->status !== VideoStatus::Published || ! $video->isForAudienceOf($user) || ! $video->isSellerEligible()) {
            return null;
        }
        // Gratuite : tout le monde la regarde en entier, sans achat ni Plus
        if ($video->price_cents === 0) {
            return 'free';
        }
        if ($video->in_plus && $video->audience?->includesOwners() && $user?->aPlus()) {
            return 'plus';
        }

        return 'preview';
    }

    /**
     * Un épisode (séries, 31.10.2026) : ce que donne sa série. Achetée, ses
     * épisodes ajoutés à la série sont à l'acheteur, même ceux d'après son
     * achat, et même s'ils ont quitté la vente depuis (comme une vidéo
     * achetée) ; jamais un épisode encore en préparation.
     */
    private function forEpisode(Video $episode, ?User $user): ?string
    {
        $series = $episode->series;
        if ($series === null) {
            return null;
        }
        if ($user !== null && $series->isOwnedBy($user)) {
            return self::wasAdded($episode) ? 'owned' : null;
        }
        if ($episode->status !== VideoStatus::Published) {
            return null;
        }
        if ($series->status !== VideoStatus::Published || ! $series->isForAudienceOf($user) || ! $series->isSellerEligible()) {
            return null;
        }
        if ($series->price_cents === 0) {
            return 'free';
        }
        if ($series->in_plus && $series->audience?->includesOwners() && $user?->aPlus()) {
            return 'plus';
        }

        return 'preview';
    }

    /** Un épisode ajouté à sa série un jour (en vente, ou sorti de la vente depuis) : ses acheteurs le gardent. */
    public static function wasAdded(Video $episode): bool
    {
        return $episode->published_at !== null && in_array($episode->status, [VideoStatus::Published, VideoStatus::Withdrawn], true);
    }
}
