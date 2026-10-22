<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\MoyenPaiement;
use App\Enums\StatutFacture;
use App\Http\Controllers\Controller;
use App\Models\Facture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Statistiques de caisse — toutes dérivées, rien de stocké : les
 * encaissements « du jour » suivent le fuseau de la clinique du jeton,
 * le CA du mois se ventile par clinique de l'organisation (champ `clin`
 * du front).
 */
class StatistiquesCaisseController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Facture::class);

        $contexte = app(ContexteClinique::class);
        $fuseau = $contexte->clinique()?->fuseau_horaire ?? 'Europe/Zurich';

        $duJour = Facture::query()
            ->where('statut', StatutFacture::Payee)
            ->whereBetween('payee_le', [
                now($fuseau)->startOfDay()->utc(),
                now($fuseau)->endOfDay()->utc(),
            ])
            ->with('lignes')
            ->get();

        $totalPar = fn (MoyenPaiement $moyen): float => round(
            (float) $duJour
                ->filter(fn (Facture $facture): bool => $facture->moyen_paiement === $moyen)
                // Ce qui est passé à la caisse : l'acompte en ligne n'y est pas
                ->sum(fn (Facture $facture): float => $facture->resteAPayer()),
            2,
        );

        $enAttente = Facture::query()
            ->where('statut', StatutFacture::Due)
            ->with('lignes')
            ->get();

        $duMois = Facture::query()
            ->where('statut', StatutFacture::Payee)
            ->where('payee_le', '>=', now($fuseau)->startOfMonth()->utc())
            ->with('lignes')
            ->get();

        $cliniques = $contexte->clinique()->societe->organisation
            ->cliniques()->pluck('cliniques.nom', 'cliniques.id');

        return response()->json(['data' => [
            'du_jour' => [
                'nombre' => $duJour->count(),
                'tpe' => $totalPar(MoyenPaiement::Tpe),
                'twint' => $totalPar(MoyenPaiement::Twint),
                'especes' => $totalPar(MoyenPaiement::Especes),
                // Acomptes payés en ligne à la réservation, déduits des factures du jour
                'acomptes' => round((float) $duJour->sum(fn (Facture $facture): float => (float) $facture->acompte), 2),
                'total' => round((float) $duJour->sum(fn (Facture $facture): float => $facture->totalTtc()), 2),
            ],
            'en_attente' => [
                'nombre' => $enAttente->count(),
                'total' => round((float) $enAttente->sum(fn (Facture $facture): float => $facture->resteAPayer()), 2),
            ],
            'mois' => [
                'ca_par_clinique' => $duMois
                    ->groupBy('clinique_id')
                    ->map(fn ($factures, int $cliniqueId): array => [
                        'clinique_id' => $cliniqueId,
                        'nom' => $cliniques[$cliniqueId] ?? null,
                        'total' => round((float) $factures->sum(fn (Facture $facture): float => $facture->totalTtc()), 2),
                    ])
                    ->values(),
            ],
        ]]);
    }
}
