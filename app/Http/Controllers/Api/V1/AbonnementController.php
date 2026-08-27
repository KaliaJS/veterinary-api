<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\FormuleSaas;
use App\Enums\PieceNumerotee;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\ChangerAbonnementRequest;
use App\Http\Resources\V1\OrganisationResource;
use App\Models\Organisation;
use App\Services\Numeroteur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abonnement SaaS (docs/data-model.md §2). Le changement de formule est
 * bloqué tant que l'organisation dépasse les limites cibles (overLims) ;
 * il exige un moyen de paiement, facture immédiatement (séquence
 * « F- », TVA 8.1 % incluse) et lève une résiliation en cours. La
 * résiliation vaut jusqu'à la fin du mois — réactivable jusque-là.
 */
class AbonnementController extends Controller
{
    public function __construct(private Numeroteur $numeroteur) {}

    public function changer(ChangerAbonnementRequest $request): OrganisationResource
    {
        $organisation = $this->organisation();
        $cible = FormuleSaas::from($request->validated('plan'));
        $cycle = $request->validated('cycle') ?? $organisation->cycle;

        $depassements = $organisation->depassementsPour($cible);

        if ($depassements !== []) {
            throw ValidationException::withMessages([
                'plan' => 'Limites de la formule cible dépassées : '.implode(' · ', $depassements).'.',
            ]);
        }

        if ($organisation->moyen_paiement === null) {
            throw ValidationException::withMessages([
                'moyen_paiement' => 'Enregistrez d’abord un moyen de paiement.',
            ]);
        }

        DB::transaction(function () use ($organisation, $cible, $cycle): void {
            $organisation->update([
                'plan' => $cible,
                'cycle' => $cycle,
                'resilie_le' => null,
            ]);

            // Dix mois pour un an (deux offerts) — parité front, TVA
            // 8.1 % incluse.
            $montant = round($cible->prixMensuel() * ($cycle === 'an' ? 10 : 1) * 1.081, 2);

            $organisation->facturesAbonnement()->create([
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::FactureAbonnement, $organisation),
                'emise_le' => today(),
                'libelle' => 'Formule '.str_replace('Veterinary ', '', $cible->nom()),
                'montant' => $montant,
            ]);
        });

        return new OrganisationResource($organisation->refresh());
    }

    public function resilier(Request $request): OrganisationResource
    {
        $organisation = $this->organisation();

        $organisation->update(['resilie_le' => today()->endOfMonth()]);

        return new OrganisationResource($organisation);
    }

    public function reactiver(Request $request): OrganisationResource
    {
        $organisation = $this->organisation();

        $organisation->update(['resilie_le' => null]);

        return new OrganisationResource($organisation);
    }

    private function organisation(): Organisation
    {
        return app(ContexteClinique::class)->clinique()->societe->organisation;
    }
}
