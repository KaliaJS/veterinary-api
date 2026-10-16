<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\FormuleSaas;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\ChangerAbonnementRequest;
use App\Http\Resources\V1\OrganisationResource;
use App\Models\Organisation;
use App\Services\Abonnements\AbonnementSaas;

/**
 * Abonnement SaaS (docs/data-model.md §2), réel : Stripe Billing
 * (AbonnementSaas). Le changement de formule est bloqué tant que
 * l'organisation dépasse les limites cibles ; il exige une carte, est
 * proratisé et facturé aussitôt. La résiliation vaut à la fin de la
 * période payée, réactivable jusque-là.
 */
class AbonnementController extends Controller
{
    public function __construct(private AbonnementSaas $abonnements) {}

    public function changer(ChangerAbonnementRequest $request): OrganisationResource
    {
        $organisation = $this->organisation();
        $this->abonnements->souscrire(
            $organisation,
            FormuleSaas::from($request->validated('plan')),
            // Mensuel : le cycle annuel n'est plus proposé (16.10.2026)
            $request->validated('cycle') ?? 'mois',
        );

        return new OrganisationResource($organisation->refresh());
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
