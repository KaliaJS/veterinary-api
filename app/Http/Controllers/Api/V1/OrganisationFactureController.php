<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FacturePlateformeResource;
use App\Models\Organisation;
use App\Models\OrganisationFacture;
use App\Services\Abonnements\AbonnementSaas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Factures de la plateforme à l'organisation du jeton (abonnement, packs,
 * transcription) : la liste, le détail à imprimer, et le règlement d'une
 * facture en attente (carte enregistrée, confirmation bancaire au besoin).
 */
class OrganisationFactureController extends Controller
{
    public function __construct(private AbonnementSaas $abonnements) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $factures = $this->organisation()
            ->facturesAbonnement()
            ->where('statut', '!=', 'annulee')
            // Anciennes factures simulées (ni Stripe ni lignes) : jamais servies
            ->where(fn ($requete) => $requete->whereNotNull('lignes')->orWhereNotNull('stripe_facture_id'))
            ->latest('emise_le')
            ->latest('id')
            ->paginate($this->parPage($request));

        return FacturePlateformeResource::collection($factures);
    }

    public function show(int $facture): FacturePlateformeResource
    {
        return (new FacturePlateformeResource($this->facture($facture)))->avecEntetes();
    }

    public function regler(int $facture): JsonResponse
    {
        $reglement = $this->abonnements->regler($this->facture($facture));

        return response()->json(['data' => new FacturePlateformeResource($reglement->facture), 'paiement' => $reglement->action()]);
    }

    public function confirmer(int $facture): JsonResponse
    {
        $reglement = $this->abonnements->confirmer($this->facture($facture));

        return response()->json(['data' => new FacturePlateformeResource($reglement->facture), 'paiement' => $reglement->action()]);
    }

    private function facture(int $id): OrganisationFacture
    {
        return $this->organisation()->facturesAbonnement()->findOrFail($id);
    }

    private function organisation(): Organisation
    {
        return app(ContexteClinique::class)->clinique()->societe->organisation;
    }
}
