<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrganisationResource;
use Illuminate\Http\Request;

/**
 * L'organisation du jeton — jamais une autre : il n'y a pas de
 * binding, la résolution part toujours du contexte.
 */
class OrganisationController extends Controller
{
    public function afficher(Request $request): OrganisationResource
    {
        return new OrganisationResource(
            app(ContexteClinique::class)->clinique()->societe->organisation,
        );
    }
}
