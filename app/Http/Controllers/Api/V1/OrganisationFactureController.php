<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrganisationFactureResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Factures d'abonnement et de packs de l'organisation du jeton —
 * lecture seule, reflets des factures Stripe (numéro, statut, PDF). Les
 * anciennes factures sans facture Stripe (simulées) ne sont plus servies.
 */
class OrganisationFactureController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $factures = app(ContexteClinique::class)->clinique()->societe->organisation
            ->facturesAbonnement()
            ->whereNotNull('stripe_facture_id')
            ->latest('emise_le')
            ->latest('id')
            ->paginate($this->parPage($request));

        return OrganisationFactureResource::collection($factures);
    }
}
