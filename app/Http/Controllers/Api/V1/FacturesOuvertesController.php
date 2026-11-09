<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutFacture;
use App\Http\Controllers\Controller;
use App\Models\Facture;
use App\Models\Settlement;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les factures ouvertes à une date (09.11.2026), pour le bouclement (les
 * débiteurs au 31 décembre) : émises jusqu'à ce jour par la clinique du
 * jeton, avec ce qui en restait à payer ce jour-là, d'après les
 * règlements reçus jusqu'à ce jour. Une vente du comptoir annulée avant
 * n'y est pas.
 */
class FacturesOuvertesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Facture::class);

        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $contexte = app(ContexteClinique::class);
        $fuseau = $contexte->clinique()?->fuseau_horaire ?: 'Europe/Zurich';
        $finDuJour = CarbonImmutable::parse($date, $fuseau)->addDay()->utc();

        $factures = Facture::query()
            ->where('clinique_id', $contexte->cliniqueId())
            ->where('emise_le', '<', $finDuJour)
            ->where(fn ($q) => $q->where('statut', '!=', StatutFacture::Annulee)->orWhere('annulee_le', '>=', $finDuJour))
            ->with(['lignes', 'settlements' => fn ($q) => $q->where('received_on', '<=', $date)])
            ->orderBy('emise_le')
            ->orderBy('id')
            ->get();

        $ouvertes = $factures
            ->map(function (Facture $facture): array {
                $total = $facture->totalTtc();
                $regle = round((float) $facture->settlements->sum(fn (Settlement $s): float => (float) $s->amount), 2);

                return [
                    'id' => $facture->id,
                    'numero' => $facture->numero,
                    'emise_le' => $facture->emise_le,
                    'patient_name' => $facture->patient_name,
                    'owner_name' => $facture->owner_name,
                    'total_ttc' => $total,
                    'regle' => $regle,
                    'reste' => max(0.0, round($total - $regle, 2)),
                ];
            })
            ->filter(fn (array $ligne): bool => $ligne['reste'] >= 0.005)
            ->values();

        return response()->json(['data' => [
            'date' => $date,
            'factures' => $ouvertes,
            'nombre' => $ouvertes->count(),
            'total_reste' => round((float) $ouvertes->sum('reste'), 2),
        ]]);
    }
}
