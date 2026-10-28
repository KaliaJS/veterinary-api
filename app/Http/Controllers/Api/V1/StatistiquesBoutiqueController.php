<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Http\Controllers\Controller;
use App\Models\CommandeBoutique;
use App\Models\FactureLigne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chiffres 7 jours de la boutique — DÉRIVÉS des lignes produit des
 * factures payées (comptoir, caisse, fin de consultation), la cible de
 * docs/data-model.md §14 : pas de table « ventes » parallèle. Périmètre
 * organisation par défaut, filtrable par clinique.
 */
class StatistiquesBoutiqueController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CommandeBoutique::class);

        $lignes = FactureLigne::query()
            ->where('type', TypeLigneFacture::Medicament)
            ->whereNotNull('produit_id')
            ->whereHas('facture', fn ($requete) => $requete
                ->where('statut', StatutFacture::Payee)
                ->where('payee_le', '>=', minuitLocal(aujourdhui()->subDays(6)))
                ->when($request->integer('clinique_id'), fn ($facture, int $clinique) => $facture->where('clinique_id', $clinique)))
            ->with(['produit', 'facture'])
            ->get();

        $ventes = $lignes
            ->map(fn (FactureLigne $ligne): array => [
                'facture_id' => $ligne->facture_id,
                'produit_id' => $ligne->produit_id,
                'nom' => $ligne->produit->nom,
                'categorie' => $ligne->produit->categorie->value,
                'quantite' => $ligne->quantite,
                'revenu' => round($ligne->quantite * (float) $ligne->prix_chf, 2),
                'cout' => round($ligne->quantite * (float) $ligne->produit->cout, 2),
                'clinique_id' => $ligne->facture->clinique_id,
                'jour' => $ligne->facture->payee_le->setTimezone(app(ContexteClinique::class)->clinique()->fuseau_horaire ?? 'Europe/Zurich')->toDateString(),
            ])
            ->values();

        $chiffreAffaires = round((float) $ventes->sum('revenu'), 2);
        $cout = round((float) $ventes->sum('cout'), 2);
        // Une vente, c'est un ticket (une facture), pas une ligne (28.10.2026)
        $tickets = $ventes->pluck('facture_id')->unique()->count();

        // Servis aussi, l'écran ne les calcule pas (28.10.2026) : chiffre par
        // clinique, meilleures ventes (cinq, au chiffre d'affaires)
        $parClinique = $ventes->groupBy('clinique_id')
            ->map(fn ($lignes, $clinique): array => ['clinique_id' => (int) $clinique, 'chiffre_affaires' => round((float) $lignes->sum('revenu'), 2)])
            ->values();
        $top = $ventes->groupBy('produit_id')
            ->map(fn ($lignes, $produit): array => ['produit_id' => (int) $produit, 'nom' => $lignes->first()['nom'], 'quantite' => (int) $lignes->sum('quantite'), 'revenu' => round((float) $lignes->sum('revenu'), 2)])
            ->sortByDesc('revenu')->take(5)->values();

        return response()->json(['data' => [
            'ventes' => $ventes,
            'par_clinique' => $parClinique,
            'top' => $top,
            'totaux' => [
                'chiffre_affaires' => $chiffreAffaires,
                'cout' => $cout,
                'marge' => round($chiffreAffaires - $cout, 2),
                'tickets' => $tickets,
                'panier_moyen' => $tickets > 0 ? round($chiffreAffaires / $tickets, 2) : null,
                'marge_pct' => $chiffreAffaires > 0 ? (int) round(($chiffreAffaires - $cout) / $chiffreAffaires * 100) : null,
            ],
        ]]);
    }
}
