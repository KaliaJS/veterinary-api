<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\SiteWeb;
use App\Services\Site\PublicShop;
use Illuminate\Http\JsonResponse;

/**
 * La page « Boutique » du site web public d'une organisation (07.11.2026),
 * sans compte et en lecture seule : la vitrine de CE site seulement (ses
 * cliniques qui ont coché la boutique, ses articles en vente libre), voir
 * PublicShop. Réserver se fait ensuite dans Mon espace, avec un compte ;
 * rien ne se paie sur le site.
 */
class SiteShopController extends Controller
{
    public function show(string $slug, PublicShop $shop): JsonResponse
    {
        $site = SiteWeb::withoutGlobalScopes()->where('slug', $slug)->where('est_publie', true)->firstOrFail();
        $organisation = Organisation::ouvertes()->findOrFail($site->organisation_id);
        $showcase = $shop->showcase($organisation);
        abort_if($showcase === null, 404);

        return response()->json(['data' => $showcase]);
    }
}
