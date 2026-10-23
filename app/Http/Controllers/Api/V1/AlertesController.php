<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\TypeAlerte;
use App\Http\Controllers\Controller;
use App\Models\Employe;
use App\Services\Alertes\AlertesClinique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alertes de la clinique pour la personne du jeton (23.10.2026) : celles
 * de ses domaines (stock, consommation), vues ou non ; et ses réglages
 * d'e-mails, une famille d'alertes à la fois.
 */
class AlertesController extends Controller
{
    public function __construct(private AlertesClinique $alertes, private ContexteClinique $contexte) {}

    public function index(): JsonResponse
    {
        $lues = $this->contexte->employe()?->alertes_lues ?? [];
        $alertes = array_map(
            fn (array $alerte) => [...$alerte, 'lue' => in_array($alerte['cle'], $lues, true)],
            $this->alertes->pour(
                $this->contexte->clinique(),
                $this->contexte->permet(DomainePermission::Stocks),
                $this->contexte->permet(DomainePermission::Organisation),
            ),
        );

        return response()->json(['data' => [
            'alertes' => $alertes,
            'non_lues' => count(array_filter($alertes, fn (array $alerte) => ! $alerte['lue'])),
        ]]);
    }

    /** Alertes vues : elles ne comptent plus, jusqu'à ce que la situation change. */
    public function lues(Request $request): JsonResponse
    {
        $cles = $request->validate(['cles' => ['required', 'array', 'max:50'], 'cles.*' => ['string', 'max:80']])['cles'];
        $employe = $this->employe();
        $employe->update(['alertes_lues' => array_slice(array_values(array_unique([...$employe->alertes_lues ?? [], ...$cles])), -200)]);

        return $this->index();
    }

    public function reglages(): JsonResponse
    {
        return $this->reponseReglages($this->employe());
    }

    /** E-mails par famille d'alertes : true, reçus ; false, coupés. */
    public function modifierReglages(Request $request): JsonResponse
    {
        $choix = $request->validate([
            TypeAlerte::Stock->value => ['sometimes', 'boolean'],
            TypeAlerte::Consommation->value => ['sometimes', 'boolean'],
        ]);
        $employe = $this->employe();
        $coupees = collect($employe->alertes_email_coupees ?? []);
        foreach ($choix as $type => $recoit) {
            $coupees = $recoit ? $coupees->reject(fn ($t) => $t === $type) : $coupees->push($type);
        }
        $employe->update(['alertes_email_coupees' => $coupees->unique()->values()->all()]);

        return $this->reponseReglages($employe);
    }

    private function reponseReglages(Employe $employe): JsonResponse
    {
        return response()->json(['data' => [
            TypeAlerte::Stock->value => $employe->recoitAlertesEmail(TypeAlerte::Stock),
            TypeAlerte::Consommation->value => $employe->recoitAlertesEmail(TypeAlerte::Consommation),
            'peut_stock' => $this->contexte->permet(DomainePermission::Stocks),
            'peut_consommation' => $this->contexte->permet(DomainePermission::Organisation),
        ]]);
    }

    private function employe(): Employe
    {
        return $this->contexte->employe() ?? abort(403);
    }
}
