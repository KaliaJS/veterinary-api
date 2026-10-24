<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Site\ConnecterDomaineRequest;
use App\Http\Resources\V1\SiteWebResource;
use App\Models\SiteWeb;
use App\Services\Domaines\DomaineSite;
use App\Services\Domaines\FournisseurIndisponible;
use Illuminate\Validation\ValidationException;

/**
 * Domaine personnalisé du site de l'organisation (étape 1 : domaine déjà
 * possédé, pointé vers nous). Mêmes droits que le site.
 */
class SiteDomaineController extends Controller
{
    public function __construct(private DomaineSite $domaines) {}

    public function connecter(ConnecterDomaineRequest $request): SiteWebResource
    {
        $this->authorize('update', SiteWeb::class);

        $site = SiteWeb::courant();
        $this->domaines->connecter($site, $request->string('domaine')->toString());

        return new SiteWebResource($site->refresh()->load('organisation'));
    }

    public function verifier(): SiteWebResource
    {
        $this->authorize('update', SiteWeb::class);

        $site = SiteWeb::courant();
        try {
            $this->domaines->verifier($site);
        } catch (FournisseurIndisponible $e) {
            throw ValidationException::withMessages(['domaine' => $e->getMessage()]);
        }

        return new SiteWebResource($site->refresh()->load('organisation'));
    }

    public function retirer(): SiteWebResource
    {
        $this->authorize('update', SiteWeb::class);

        $site = SiteWeb::courant();
        $this->domaines->retirer($site);

        return new SiteWebResource($site->refresh()->load('organisation'));
    }
}
