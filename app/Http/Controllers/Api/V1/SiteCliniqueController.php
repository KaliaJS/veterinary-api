<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Site\ModifierSiteRequest;
use App\Http\Resources\V1\SiteCliniqueResource;
use App\Models\Employe;
use App\Models\SiteClinique;

/**
 * Site web de la clinique du jeton : une ressource par clinique, créée
 * à la première lecture (non publiée, slug tiré du nom, services de
 * départ, équipe reprise des employés de la clinique).
 */
class SiteCliniqueController extends Controller
{
    public function afficher(): SiteCliniqueResource
    {
        $this->authorize('viewAny', SiteClinique::class);

        return new SiteCliniqueResource($this->site());
    }

    public function modifier(ModifierSiteRequest $request): SiteCliniqueResource
    {
        $this->authorize('update', SiteClinique::class);

        $site = $this->site();
        $site->update($request->validated());

        return new SiteCliniqueResource($site->refresh());
    }

    private function site(): SiteClinique
    {
        $site = SiteClinique::query()->with('clinique')->first();

        if ($site) {
            return $site;
        }

        $clinique = app(ContexteClinique::class)->clinique();

        $site = SiteClinique::create([
            'slug' => SiteClinique::slugDisponible($clinique->nom),
            'services' => SiteClinique::SERVICES_DE_DEPART,
            'equipe' => Employe::query()
                ->where('acces_suspendu', false)
                ->orderBy('nom')
                ->get()
                ->map(fn (Employe $employe): array => ['nom' => $employe->nom, 'role' => $employe->role_libelle, 'bio' => null])
                ->values()
                ->all(),
            'actualites' => [],
        ]);
        $site->wasRecentlyCreated = false;

        return $site->load('clinique');
    }
}
