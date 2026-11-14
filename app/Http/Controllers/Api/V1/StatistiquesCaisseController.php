<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\SettlementMethod;
use App\Enums\StatutFacture;
use App\Http\Controllers\Controller;
use App\Models\Facture;
use App\Models\Settlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Statistiques de caisse — toutes dérivées, rien de stocké : les
 * encaissements « du jour » suivent le fuseau de la clinique du jeton,
 * le CA du mois se ventile par clinique de l'organisation (champ `clin`
 * du front). Le CA du mois ne sort que pour l'administrateur et le droit
 * « Voir les chiffres » (audit C11, 14.11.2026) ; l'encaissé du jour et
 * les factures en attente restent à la facturation (la clôture en a
 * besoin).
 */
class StatistiquesCaisseController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Facture::class);

        $contexte = app(ContexteClinique::class);
        $fuseau = $contexte->clinique()->fuseau_horaire ?? 'Europe/Zurich';

        // L'encaissé du jour : les règlements reçus ce jour (09.11.2026), paiements
        // partiels compris, annulations et remboursements déduits
        $duJour = Settlement::query()
            ->whereDate('received_on', now($fuseau)->toDateString())
            ->get();

        $totalPar = fn (SettlementMethod $moyen): float => round(
            (float) $duJour->filter(fn (Settlement $s): bool => $s->method === $moyen)->sum(fn (Settlement $s): float => (float) $s->amount),
            2,
        );

        $enAttente = Facture::query()
            ->where('statut', StatutFacture::Due)
            ->with(['lignes', 'settlements'])
            ->get();

        return response()->json(['data' => [
            'du_jour' => [
                // Les factures qui ont reçu un paiement ce jour
                'nombre' => $duJour->filter(fn (Settlement $s): bool => (float) $s->amount > 0)->pluck('facture_id')->unique()->count(),
                'tpe' => $totalPar(SettlementMethod::Tpe),
                'twint' => $totalPar(SettlementMethod::Twint),
                'especes' => $totalPar(SettlementMethod::Especes),
                // QR-factures payées par virement, hors caisse
                'virement' => $totalPar(SettlementMethod::Virement),
                // Acomptes payés en ligne ce jour-là, une fois sur une facture (datés de leur paiement)
                'acomptes' => $totalPar(SettlementMethod::Acompte),
                'total' => round((float) $duJour->sum(fn (Settlement $s): float => (float) $s->amount), 2),
            ],
            'en_attente' => [
                'nombre' => $enAttente->count(),
                'total' => round((float) $enAttente->sum(fn (Facture $facture): float => $facture->resteAPayer()), 2),
            ],
            ...$request->user()->can('viewChiffres', Facture::class) ? ['mois' => $this->duMois($fuseau)] : [],
        ]]);
    }

    /**
     * Le CA du mois, par clinique de l'organisation : `ca_par_clinique`
     * (clinique_id, nom, total).
     *
     * @return array<string, mixed>
     */
    private function duMois(string $fuseau): array
    {
        $duMois = Facture::query()
            ->where('statut', StatutFacture::Payee)
            ->where('payee_le', '>=', now($fuseau)->startOfMonth()->utc())
            ->with('lignes')
            ->get();

        $cliniques = app(ContexteClinique::class)->clinique()->societe->organisation
            ->cliniques()->pluck('cliniques.nom', 'cliniques.id');

        return [
            'ca_par_clinique' => $duMois
                ->groupBy('clinique_id')
                ->map(fn ($factures, int $cliniqueId): array => [
                    'clinique_id' => $cliniqueId,
                    'nom' => $cliniques[$cliniqueId] ?? null,
                    'total' => round((float) $factures->sum(fn (Facture $facture): float => $facture->totalTtc()), 2),
                ])
                ->values(),
        ];
    }
}
