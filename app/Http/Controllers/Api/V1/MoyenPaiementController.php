<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\EnregistrerMoyenPaiementRequest;
use App\Http\Resources\V1\OrganisationResource;
use App\Models\Organisation;
use App\Services\Abonnements\AbonnementSaas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carte de l'abonnement, chez Stripe : l'intention d'enregistrement sert
 * au Payment Element du navigateur (la carte ne passe jamais par nos
 * serveurs), puis la carte confirmée devient celle des factures ; seul
 * son libellé masqué est gardé (« Visa •••• 4242 · exp. 08/28 »).
 */
class MoyenPaiementController extends Controller
{
    public function __construct(private AbonnementSaas $abonnements) {}

    public function intention(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->abonnements->intentionCarte($this->organisation(), $request->user()?->email)]);
    }

    public function enregistrer(EnregistrerMoyenPaiementRequest $request): OrganisationResource
    {
        $organisation = $this->organisation();
        $this->abonnements->enregistrerCarte($organisation, $request->validated('payment_method'));

        return new OrganisationResource($organisation->refresh());
    }

    public function supprimer(): OrganisationResource
    {
        $organisation = $this->organisation();
        $this->abonnements->supprimerCarte($organisation);

        return new OrganisationResource($organisation->refresh());
    }

    private function organisation(): Organisation
    {
        return app(ContexteClinique::class)->clinique()->societe->organisation;
    }
}
