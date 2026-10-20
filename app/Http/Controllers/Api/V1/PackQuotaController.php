<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\TypePack;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\AcheterPackRequest;
use App\Http\Resources\V1\OrganisationResource;
use App\Services\Abonnements\AbonnementSaas;
use Illuminate\Http\JsonResponse;

/**
 * Packs de quota (docs/data-model.md §2) : palier du catalogue, prix du
 * serveur (sans TVA), facturé et prélevé aussitôt sur la carte ; la
 * quantité s'ajoute une fois la facture payée (après la confirmation
 * bancaire si la banque la demande). E-mails et SMS s'ajoutent au mois en
 * cours, le stockage est permanent.
 */
class PackQuotaController extends Controller
{
    public function __construct(private AbonnementSaas $abonnements) {}

    public function acheter(AcheterPackRequest $request): JsonResponse
    {
        $organisation = app(ContexteClinique::class)->clinique()->societe->organisation;
        $reglement = $this->abonnements->acheterPack($organisation, TypePack::from($request->validated('type')), (int) $request->validated('quantite'));

        return response()->json(['data' => new OrganisationResource($organisation->refresh()), 'paiement' => $reglement->action()]);
    }
}
