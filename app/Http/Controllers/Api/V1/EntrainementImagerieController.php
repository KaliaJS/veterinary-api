<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrganisationResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Accord de l'organisation pour entraîner des modèles sur ses radios
 * anonymisées (28.10.2026) : donné ou retiré par le droit organisation,
 * daté, avec qui l'a donné. Sans accord, aucune image n'est utilisable
 * (ClicheDicom::entrainementPermis). Les CGU le disent.
 */
class EntrainementImagerieController extends Controller
{
    public function __invoke(Request $request): OrganisationResource
    {
        Gate::authorize(DomainePermission::Organisation->value);
        $accord = $request->validate(['accord' => ['required', 'boolean']])['accord'];
        $organisation = app(ContexteClinique::class)->clinique()->societe->organisation;
        $organisation->forceFill([
            'entrainement_consenti_le' => $accord ? ($organisation->entrainement_consenti_le ?? now()) : null,
            'entrainement_consenti_par' => $accord ? ($organisation->entrainement_consenti_par ?? $request->user()?->id) : null,
        ])->save();

        return new OrganisationResource($organisation->refresh());
    }
}
