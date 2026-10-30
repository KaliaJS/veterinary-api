<?php

namespace App\Services\Videos;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\FormuleSaas;
use App\Models\Clinique;
use App\Models\Organisation;
use App\Models\User;
use App\Models\Video;
use Illuminate\Validation\ValidationException;

/**
 * Qui vend (décision de Jim, 31.10.2026) : un vétérinaire en son nom, ou
 * une clinique. Seul un vendeur vérifié vend : le vétérinaire vérifié
 * (Stripe Identity, ou à la main après un échec), et pour une clinique,
 * une organisation ouverte et une personne vérifiée qui en gère
 * l'organisation.
 */
class Sellers
{
    public function __construct(private ContexteClinique $context) {}

    /**
     * Le vendeur d'une nouvelle vidéo.
     *
     * @return array{seller_user_id: ?int, seller_clinique_id: ?int, organisation_id: ?int, seller_name: string}
     */
    public function resolve(User $user, string $as): array
    {
        $this->assertVerified($user);

        if ($as === 'me') {
            return ['seller_user_id' => $user->id, 'seller_clinique_id' => null, 'organisation_id' => null, 'seller_name' => $user->name];
        }

        $clinique = $this->assertClinicSeller();

        return [
            'seller_user_id' => null,
            'seller_clinique_id' => $clinique->id,
            'organisation_id' => $this->context->organisationId(),
            'seller_name' => $clinique->nom,
        ];
    }

    /**
     * Peut encore agir sur cette vidéo comme son vendeur (modifier, envoyer,
     * publier, retirer) : toujours vérifié, et pour une clinique, toujours
     * ouverte et gérée par lui.
     */
    public function assertCanManage(Video $video, User $user, string $as): void
    {
        $mine = $as === 'me'
            ? $video->seller_user_id === $user->id
            : $video->seller_clinique_id !== null && $video->seller_clinique_id === $this->context->cliniqueId();
        if (! $mine) {
            abort(404);
        }
        $this->assertVerified($user);
        if ($as !== 'me') {
            $this->assertClinicSeller();
        }
    }

    /**
     * Le compte de paiement qui encaisse les ventes de la vidéo.
     */
    public function stripeAccount(Video $video): string
    {
        if ($video->seller_user_id !== null) {
            $seller = $video->sellerUser;
            if ($seller?->stripe_account_id === null || $seller->stripe_account_ready_at === null) {
                throw ValidationException::withMessages(['compte' => 'Le vendeur n’a pas encore de compte de paiement actif.']);
            }

            return $seller->stripe_account_id;
        }

        $societe = $video->sellerClinique?->societe;
        if (! $societe?->stripe_compte_id || ! $societe->paiements_actifs) {
            throw ValidationException::withMessages(['compte' => 'Les paiements en ligne ne sont pas encore activés pour cette clinique : un administrateur les active dans Réglages, Paiements.']);
        }

        return $societe->stripe_compte_id;
    }

    /**
     * Le stockage du vendeur : 100 Go pour un vétérinaire en son nom, ceux
     * de l'organisation (1000 Go et ses packs) pour une clinique.
     *
     * @return array{used: int, limit: int}
     */
    public function storage(Video $video): array
    {
        if ($video->seller_user_id !== null) {
            return [
                'used' => (int) Video::query()->where('seller_user_id', $video->seller_user_id)->sum('size_bytes'),
                'limit' => Video::PERSONAL_STORAGE_BYTES,
            ];
        }

        $organisation = Organisation::query()->findOrFail($video->organisation_id);

        return [
            'used' => $organisation->stockageOctets(),
            'limit' => (FormuleSaas::STOCKAGE_GO + (int) $organisation->quota_extra_stockage_go) * 1000 ** 3,
        ];
    }

    private function assertVerified(User $user): void
    {
        if (! $user->estVerifie()) {
            throw ValidationException::withMessages(['vendeur' => 'Vendre une vidéo demande un compte de vétérinaire vérifié : vérifiez votre identité dans Réglages, Profil.']);
        }
    }

    private function assertClinicSeller(): Clinique
    {
        $clinique = $this->context->clinique();
        if ($clinique === null) {
            throw ValidationException::withMessages(['vendeur' => 'Ouvrez Veterinary dans la clinique qui vend la vidéo.']);
        }
        if (! $this->context->permet(DomainePermission::Organisation)) {
            throw ValidationException::withMessages(['vendeur' => 'Vendre au nom de la clinique demande le droit Administration.']);
        }
        $organisation = Organisation::query()->find($this->context->organisationId());
        if ($organisation === null || ! $organisation->estOuverte()) {
            throw ValidationException::withMessages(['vendeur' => 'Votre organisation n’est pas encore ouverte : elle ne vend pas encore de vidéos.']);
        }

        return $clinique;
    }
}
