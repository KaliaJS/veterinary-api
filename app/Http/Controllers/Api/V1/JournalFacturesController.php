<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutFacture;
use App\Http\Controllers\Controller;
use App\Models\Facture;
use App\Models\FactureLigne;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal d'un mois de la clinique du jeton (23.10.2026) : les factures
 * émises (journal des ventes, CSV) et les encaissements avec leurs
 * totaux (décompte TVA). Tout est servi, pages comprises : le front ne
 * recompose plus un mois à partir des cent dernières factures chargées.
 * Le mois se compte à l'heure de la clinique.
 */
class JournalFacturesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Facture::class);

        $mois = $request->validate(['mois' => ['required', 'date_format:Y-m']])['mois'];

        $contexte = app(ContexteClinique::class);
        $fuseau = $contexte->clinique()?->fuseau_horaire ?: 'Europe/Zurich';
        $debut = CarbonImmutable::parse($mois.'-01', $fuseau);
        $fin = $debut->addMonth();
        [$du, $au] = [$debut->utc(), $fin->utc()];

        $base = Facture::query()
            ->with('lignes')
            ->where('clinique_id', $contexte->cliniqueId())
            ->orderBy('emise_le')
            ->orderBy('id');

        $emises = (clone $base)->whereBetween('emise_le', [$du, $au->subSecond()])->get();
        $encaissees = (clone $base)
            ->where('statut', StatutFacture::Payee)
            ->whereBetween('payee_le', [$du, $au->subSecond()])
            ->get();

        return response()->json(['data' => [
            'mois' => $mois,
            'taux_tva' => $contexte->clinique()?->societe?->tauxTva() ?? Facture::TAUX_TVA * 100,
            'emises' => $emises->map(fn (Facture $facture): array => $this->ligne($facture))->values(),
            'encaissements' => [
                'factures' => $encaissees->map(fn (Facture $facture): array => $this->ligne($facture))->values(),
                'nombre' => $encaissees->count(),
                'total_ttc' => round($encaissees->sum(fn (Facture $facture): float => $facture->totalTtc()), 2),
                'montant_tva' => round($encaissees->sum(fn (Facture $facture): float => $facture->montantTva()), 2),
                'total_ht' => round($encaissees->sum(fn (Facture $facture): float => $facture->totalHt()), 2),
            ],
        ]]);
    }

    /** @return array<string, mixed> */
    private function ligne(Facture $facture): array
    {
        return [
            'numero' => $facture->numero,
            'emise_le' => $facture->emise_le,
            'payee_le' => $facture->payee_le,
            'patient_name' => $facture->patient_name,
            'owner_name' => $facture->owner_name,
            'detail' => $facture->lignes->map(fn (FactureLigne $ligne): string => $ligne->libelle.' x'.$ligne->quantite)->implode(' | '),
            'total_ttc' => $facture->totalTtc(),
            'montant_tva' => $facture->montantTva(),
            'moyen_paiement' => $facture->moyen_paiement,
            'statut' => $facture->statut,
        ];
    }
}
