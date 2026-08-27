<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\EnregistrerMoyenPaiementRequest;
use App\Http\Resources\V1\OrganisationResource;
use Illuminate\Http\Request;

/**
 * Carte de l'abonnement : seul le libellé masqué est conservé
 * (« Visa •••• 4242 ») — le numéro complet ne touche jamais la base.
 */
class MoyenPaiementController extends Controller
{
    public function enregistrer(EnregistrerMoyenPaiementRequest $request): OrganisationResource
    {
        $organisation = app(ContexteClinique::class)->clinique()->societe->organisation;

        $chiffres = preg_replace('/\D/', '', $request->validated('numero'));

        $organisation->update(['moyen_paiement' => 'Visa •••• '.substr($chiffres, -4)]);

        return new OrganisationResource($organisation);
    }

    public function supprimer(Request $request): OrganisationResource
    {
        $organisation = app(ContexteClinique::class)->clinique()->societe->organisation;

        $organisation->update(['moyen_paiement' => null]);

        return new OrganisationResource($organisation);
    }
}
