<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CategorieProduit;
use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\SiteWeb;
use App\Services\Site\PublicShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

/**
 * La page « Boutique » du site web public d'une organisation (07.11.2026),
 * sans compte et en lecture seule : la vitrine de CE site seulement (ses
 * cliniques qui ont coché la boutique, ses articles en vente libre), voir
 * PublicShop. Paginée (24 par page), une famille filtrée au serveur
 * (`famille`) ; les familles de toute la boutique dans `meta.facettes`.
 * L'éditeur du site lit la même vitrine avec son jeton (`preview`), même
 * avant publication. Réserver se fait ensuite dans Mon espace, avec un
 * compte ; rien ne se paie sur le site.
 */
class SiteShopController extends Controller
{
    public const int PER_PAGE = 24;

    public function index(Request $request, string $slug, PublicShop $shop): AnonymousResourceCollection
    {
        return $this->page($request, $this->organisation($slug), $shop);
    }

    public function show(string $slug, int $article, PublicShop $shop): JsonResponse
    {
        $data = $shop->article($this->organisation($slug), $article);
        abort_if($data === null, 404);

        return response()->json(['data' => $data]);
    }

    /** L'aperçu de l'éditeur : la vitrine de l'organisation du jeton */
    public function preview(Request $request, PublicShop $shop): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SiteWeb::class);

        return $this->page($request, SiteWeb::courant()->organisation, $shop);
    }

    private function page(Request $request, Organisation $organisation, PublicShop $shop): AnonymousResourceCollection
    {
        $request->validate(['famille' => ['sometimes', 'nullable', Rule::enum(CategorieProduit::class)]]);
        $page = $shop->page($organisation, CategorieProduit::tryFrom((string) $request->query('famille')), $this->parPage($request, self::PER_PAGE));
        abort_if($page === null, 404);

        return JsonResource::collection($page)->additional(['meta' => ['facettes' => $shop->families($organisation)]]);
    }

    private function organisation(string $slug): Organisation
    {
        $site = SiteWeb::withoutGlobalScopes()->where('slug', $slug)->where('est_publie', true)->firstOrFail();

        return Organisation::ouvertes()->findOrFail($site->organisation_id);
    }
}
