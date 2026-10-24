<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Site\TeleverserLogoRequest;
use App\Http\Resources\V1\SiteWebResource;
use App\Models\SiteWeb;
use App\Services\Photos\ImageReencodee;
use Illuminate\Support\Facades\Storage;

/**
 * Logo de l'organisation (site web, annuaire) : un fichier privé par
 * organisation, remplacé à chaque envoi, servi par la route publique
 * du site. Mêmes droits que le site (domaine organisation).
 */
class SiteLogoController extends Controller
{
    public function televerser(TeleverserLogoRequest $request): SiteWebResource
    {
        $this->authorize('update', SiteWeb::class);

        $site = $this->site();
        $ancien = $site->logo_chemin;

        // Réécrit (24.10.2026) : servi en public, sans les métadonnées du fichier déposé
        $chemin = ImageReencodee::logo($request->file('logo'), "logos/{$site->organisation_id}", 800);
        $site->update(['logo_chemin' => $chemin]);

        if ($ancien && $ancien !== $chemin) {
            Storage::disk('prive')->delete($ancien);
        }

        return new SiteWebResource($site->refresh()->load('organisation'));
    }

    public function supprimer(): SiteWebResource
    {
        $this->authorize('update', SiteWeb::class);

        $site = $this->site();

        if ($site->logo_chemin) {
            Storage::disk('prive')->delete($site->logo_chemin);
            $site->update(['logo_chemin' => null]);
        }

        return new SiteWebResource($site->refresh()->load('organisation'));
    }

    private function site(): SiteWeb
    {
        return SiteWeb::courant();
    }
}
