<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Site\ModifierSiteRequest;
use App\Http\Resources\V1\SiteWebResource;
use App\Models\SiteWeb;

/**
 * Site web de l'organisation du jeton : une ressource par organisation,
 * créée à la première lecture (non publiée, slug tiré du nom, services
 * de départ, équipe reprise des employés actifs de toutes ses cliniques).
 */
class SiteWebController extends Controller
{
    public function afficher(): SiteWebResource
    {
        $this->authorize('viewAny', SiteWeb::class);

        return new SiteWebResource($this->site());
    }

    public function modifier(ModifierSiteRequest $request): SiteWebResource
    {
        $this->authorize('update', SiteWeb::class);

        $site = $this->site();
        $site->update($request->validated());

        return new SiteWebResource($site->refresh()->load('organisation'));
    }

    private function site(): SiteWeb
    {
        return SiteWeb::courant();
    }
}
