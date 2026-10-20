<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\FormuleSaas;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\ChangerAbonnementRequest;
use App\Http\Resources\V1\OrganisationResource;
use App\Models\Organisation;
use App\Services\Abonnements\AbonnementSaas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Abonnement de l'organisation (docs/data-model.md §2), tenu par
 * l'application (AbonnementSaas) : souscription payée d'avance, formule
 * plus chère aussitôt (crédit du temps non utilisé), moins chère au
 * renouvellement ; bloqué tant que l'organisation dépasse les limites
 * cibles. La résiliation vaut à la fin de la période payée, réactivable
 * jusque-là. Quand la banque demande une confirmation, la réponse porte
 * `paiement` (secret pour Stripe.js), puis la page appelle
 * `organisation/factures/{id}/confirmer`.
 */
class AbonnementController extends Controller
{
    public function __construct(private AbonnementSaas $abonnements) {}

    public function changer(ChangerAbonnementRequest $request): JsonResponse
    {
        $organisation = $this->organisation();
        $reglement = $this->abonnements->souscrire($organisation, FormuleSaas::from($request->validated('plan')));

        return response()->json(['data' => new OrganisationResource($organisation->refresh()), 'paiement' => $reglement?->action()]);
    }

    /** Récapitulatif avant paiement : ce que coûterait le passage à la formule. */
    public function apercu(Request $request): JsonResponse
    {
        $plan = $request->validate(['plan' => ['required', Rule::enum(FormuleSaas::class)]])['plan'];

        return response()->json(['data' => $this->abonnements->apercu($this->organisation(), FormuleSaas::from($plan))]);
    }

    public function resilier(): OrganisationResource
    {
        $organisation = $this->organisation();
        $this->abonnements->resilier($organisation);

        return new OrganisationResource($organisation->refresh());
    }

    public function reactiver(): OrganisationResource
    {
        $organisation = $this->organisation();
        $this->abonnements->reactiver($organisation);

        return new OrganisationResource($organisation->refresh());
    }

    private function organisation(): Organisation
    {
        return app(ContexteClinique::class)->clinique()->societe->organisation;
    }
}
