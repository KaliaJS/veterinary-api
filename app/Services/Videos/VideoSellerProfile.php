<?php

namespace App\Services\Videos;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\SiteWeb;
use App\Models\SpecialiteVeterinaire;
use App\Models\Video;
use App\Services\Photos\PhotosCliniques;

/**
 * Qui présente une vidéo, dans sa fenêtre « Plus d'infos » (31.10.2026,
 * demande de Jim) : un vétérinaire, sa photo, ses spécialités et ce qu'il
 * écrit de lui ; une clinique, son logo, sa photo, sa ville et la
 * présentation de son site. Seulement ce qui est déjà public ailleurs : la
 * fiche d'un vétérinaire qui a accepté l'annuaire (nLPD), le site publié
 * d'une clinique. Un vendeur effacé garde son nom, rien d'autre.
 */
class VideoSellerProfile
{
    /** @return array<string, mixed> */
    public function for(Video $video): array
    {
        $profile = [
            'name' => $video->seller_name,
            'kind' => $video->seller_clinique_id !== null ? 'clinic' : 'vet',
            'is_verified' => false,
            'photo_url' => null,
            'logo_url' => null,
            'place' => null,
            'specialities' => [],
            'about' => null,
            'site_url' => null,
        ];

        if ($video->seller_user_id !== null) {
            $vet = $video->sellerUser;
            if ($vet === null) {
                return $profile;
            }
            $profile['is_verified'] = $vet->estVerifie();
            if ($vet->estNommableDansLAnnuaire()) {
                // Sa photo de clinique, servie seulement s'il l'accepte (Employe::photoUrl)
                $profile['photo_url'] = Employe::withoutGlobalScopes()->where('user_id', $vet->id)->whereNotNull('photo_chemin')->get()
                    ->map(fn (Employe $fiche) => $fiche->photoUrl())->filter()->first();
                $profile['specialities'] = $vet->specialites()->get()
                    ->map(fn (SpecialiteVeterinaire $ligne) => $ligne->specialite->libelle())->values()->all();
                $profile['about'] = $vet->presentation;
            }

            return $profile;
        }

        $clinique = $video->seller_clinique_id === null ? null : Clinique::withoutGlobalScopes()->find($video->seller_clinique_id);
        if ($clinique === null) {
            return $profile;
        }
        $site = SiteWeb::withoutGlobalScopes()->where('organisation_id', $video->organisation_id)->first();
        $profile['place'] = $clinique->ville;
        // Le logo se montre dans l'annuaire, site publié ou non ; sa présentation, seulement publiée
        $profile['logo_url'] = $site?->logoUrl();
        $profile['photo_url'] = PhotosCliniques::pour($clinique)['url'] ?? null;
        if ($site?->est_publie) {
            $profile['about'] = $site->presentation ?: $site->accroche;
            $profile['site_url'] = $site->urlPublique();
        }

        return $profile;
    }
}
