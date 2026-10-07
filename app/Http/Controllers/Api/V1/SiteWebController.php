<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Site\ModifierSiteRequest;
use App\Http\Resources\V1\SiteWebResource;
use App\Models\Employe;
use App\Models\Organisation;
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
        $site = SiteWeb::query()->with('organisation')->first();

        if ($site) {
            return $site;
        }

        $organisation = Organisation::findOrFail(app(ContexteClinique::class)->organisationId());
        $cliniques = $organisation->cliniques()->withoutGlobalScopes()->pluck('cliniques.nom', 'cliniques.id');
        $plusieurs = $cliniques->count() > 1;

        $site = SiteWeb::create([
            'slug' => SiteWeb::slugDisponible($organisation->nom),
            'services' => SiteWeb::SERVICES_DE_DEPART,
            'equipe' => Employe::withoutGlobalScopes()
                ->whereIn('clinique_id', $cliniques->keys())
                ->where('acces_suspendu', false)
                ->orderBy('nom')
                ->get()
                ->map(fn (Employe $employe): array => [
                    'nom' => $employe->nom,
                    'role' => $employe->role_libelle,
                    'bio' => null,
                    'clinique' => $plusieurs ? $cliniques[$employe->clinique_id] : null,
                ])
                ->values()
                ->all(),
            'actualites' => [],
        ]);
        $site->wasRecentlyCreated = false;

        return $site->load('organisation');
    }
}
