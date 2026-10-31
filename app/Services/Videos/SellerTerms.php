<?php

namespace App\Services\Videos;

use App\Auth\ContexteClinique;
use App\Models\Organisation;
use App\Models\User;
use App\Models\Video;

/**
 * Les conditions de vente des vidéos de formation (31.10.2026, demande de
 * Jim) : acceptées avant la première mise en vente, par le vétérinaire qui
 * vend en son nom, ou pour la clinique par la personne qui publie. Une
 * nouvelle version (le texte de la page publique a changé) se réaccepte à
 * la mise en vente suivante.
 */
class SellerTerms
{
    /** La date de la version en vigueur, la même que celle de la page publique du web. */
    public const VERSION = '2026-10-31';

    public function __construct(private ContexteClinique $context) {}

    public function isAccepted(Video $video): bool
    {
        $holder = $video->seller_user_id !== null ? User::query()->find($video->seller_user_id) : Organisation::query()->find($video->organisation_id);

        return $holder?->video_terms_version === self::VERSION;
    }

    /** Pour l'écran du vendeur : en son nom (`me`) ou pour la clinique de la session (`clinic`). */
    public function acceptedFor(User $user, string $as): bool
    {
        $holder = $as === 'me' ? $user : Organisation::query()->find($this->context->organisationId());

        return $holder?->video_terms_version === self::VERSION;
    }

    public function accept(Video $video, User $by): void
    {
        $values = ['video_terms_version' => self::VERSION, 'video_terms_accepted_at' => now()];
        if ($video->seller_user_id !== null) {
            User::query()->whereKey($video->seller_user_id)->update($values);

            return;
        }
        Organisation::query()->whereKey($video->organisation_id)->update([...$values, 'video_terms_accepted_by' => $by->id]);
    }
}
