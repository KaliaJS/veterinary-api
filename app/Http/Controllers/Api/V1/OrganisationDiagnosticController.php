<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Diagnostic\ReglagesDiagnosticRequest;
use App\Models\Organisation;
use App\Services\Diagnostic\ConsommationDiagnostic;
use Illuminate\Http\JsonResponse;

/**
 * Suggestions diagnostiques de l'organisation (domaine organisation) :
 * consommation de la période, tarif et plafond.
 */
class OrganisationDiagnosticController extends Controller
{
    public function __construct(private ConsommationDiagnostic $consommation) {}

    public function afficher(): JsonResponse
    {
        return response()->json(['data' => $this->consommation->etat($this->organisation())]);
    }

    public function modifier(ReglagesDiagnosticRequest $request): JsonResponse
    {
        $organisation = $this->organisation();
        $organisation->forceFill(['plafond_diagnostic' => (int) round((float) $request->validated('plafond') * 100)])->save();

        return response()->json(['data' => $this->consommation->etat($organisation)]);
    }

    private function organisation(): Organisation
    {
        return Organisation::query()->findOrFail(app(ContexteClinique::class)->organisationId());
    }
}
