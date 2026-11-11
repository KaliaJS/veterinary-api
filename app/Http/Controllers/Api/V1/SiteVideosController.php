<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\SiteWeb;
use App\Services\Site\SiteVideos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La page « Vidéos » du site web public d'une organisation (11.11.2026),
 * sans compte et en lecture seule : les vidéos de CE site seulement, voir
 * SiteVideos. L'éditeur lit la même page avec son jeton (`preview`), même
 * avant publication. Acheter et regarder se font ensuite sur Veterinary.
 */
class SiteVideosController extends Controller
{
    public function index(Request $request, string $slug, SiteVideos $videos): JsonResponse
    {
        $site = SiteWeb::withoutGlobalScopes()->with('organisation')->where('slug', $slug)->where('est_publie', true)->firstOrFail();
        Organisation::ouvertes()->findOrFail($site->organisation_id);
        abort_unless($videos->isOpen($site), 404);

        return response()->json(['data' => ['rows' => $videos->rows($site->organisation, $request)]]);
    }

    /** L'aperçu de l'éditeur : la page de l'organisation du jeton */
    public function preview(Request $request, SiteVideos $videos): JsonResponse
    {
        $this->authorize('viewAny', SiteWeb::class);
        $site = SiteWeb::courant();

        return response()->json(['data' => ['rows' => $videos->rows($site->organisation, $request)]]);
    }
}
