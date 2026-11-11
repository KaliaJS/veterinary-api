<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\ModifierReglagesCongesRequest;
use App\Models\Organisation;
use App\Services\Equipe\CongesATraiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Les congés vus par qui gère l'équipe (droit Équipe) : la cloche (ce
 * qui est à traiter) et les règles de l'organisation, que seul le droit
 * Ressources humaines change (Jim, 11.11.2026, É10 et É11).
 */
class CongesEquipeController extends Controller
{
    public function aTraiter(CongesATraiter $conges): JsonResponse
    {
        $aTraiter = $conges->pour($this->organisation());

        return response()->json([
            'data' => $aTraiter,
            'meta' => ['total' => count($aTraiter['a_valider']) + count($aTraiter['a_planifier']) + count($aTraiter['deux_semaines'])],
        ]);
    }

    public function reglages(): JsonResponse
    {
        return response()->json(['data' => self::regles($this->organisation())]);
    }

    public function modifierReglages(ModifierReglagesCongesRequest $request): JsonResponse
    {
        Gate::authorize(DomainePermission::Rh->value);
        $organisation = $this->organisation();
        foreach ($request->validated() as $cle => $valeur) {
            $organisation->{'conges_'.$cle} = $valeur;
        }
        $organisation->save();

        return response()->json(['data' => self::regles($organisation)]);
    }

    /** @return array{droit_defaut: int, seuil_report: int, planifier_avant: string, rappel_deux_semaines: bool, sans_validation: bool} */
    public static function regles(Organisation $organisation): array
    {
        return [
            'droit_defaut' => $organisation->conges_droit_defaut,
            'seuil_report' => $organisation->conges_seuil_report,
            'planifier_avant' => $organisation->conges_planifier_avant,
            'rappel_deux_semaines' => $organisation->conges_rappel_deux_semaines,
            'sans_validation' => $organisation->conges_sans_validation,
        ];
    }

    private function organisation(): Organisation
    {
        return Organisation::query()->findOrFail(app(ContexteClinique::class)->organisationId());
    }
}
