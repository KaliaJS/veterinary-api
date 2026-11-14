<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Http\Controllers\Controller;
use App\Models\CommandeBoutique;
use App\Models\Facture;
use App\Models\FactureLigne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Chiffres de la boutique — DÉRIVÉS des lignes produit des factures payées
 * du comptoir (audit C11, 14.11.2026 : les consultations et la caisse n'en
 * sont plus, conformément à docs/data-model.md §14) : pas de table
 * « ventes » parallèle. Périmètre organisation par défaut, filtrable par
 * clinique. Réservés à l'administrateur et au droit « Voir les chiffres »
 * (FacturePolicy::viewChiffres), en plus du droit Stocks de la boutique.
 *
 * La période (Jim, 11.11.2026, maquette /ui/boutique-refonte, choix B) :
 * 7 jours (par défaut) ou 30 jours, jour par jour ; 12 mois, mois par mois
 * (le mois en cours et les onze d'avant). Servis aussi, l'écran ne calcule
 * rien : la série du chiffre d'affaires, les totaux de la période d'avant
 * (de même longueur, juste avant) et l'évolution de chaque nombre (en %,
 * en points pour la marge), la part de chaque famille.
 */
class StatistiquesBoutiqueController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CommandeBoutique::class);
        $this->authorize('viewChiffres', Facture::class);
        $request->validate(['periode' => ['nullable', 'integer', 'in:7,30,365']]);

        $periode = $request->integer('periode') ?: 7;
        $parMois = $periode === 365;
        $aujourdhui = aujourdhui();
        $debut = $parMois ? $aujourdhui->copy()->startOfMonth()->subMonthsNoOverflow(11) : $aujourdhui->copy()->subDays($periode - 1);
        $debutAvant = $parMois ? $debut->copy()->subMonthsNoOverflow(12) : $debut->copy()->subDays($periode);
        $fuseau = app(ContexteClinique::class)->clinique()->fuseau_horaire ?? 'Europe/Zurich';

        $lignes = FactureLigne::query()
            ->where('type', TypeLigneFacture::Medicament)
            ->whereNotNull('produit_id')
            ->whereHas('facture', fn ($requete) => $requete
                ->where('vente_comptoir', true)
                ->where('statut', StatutFacture::Payee)
                ->where('payee_le', '>=', minuitLocal($debutAvant))
                ->when($request->integer('clinique_id'), fn ($facture, int $clinique) => $facture->where('clinique_id', $clinique)))
            ->with(['produit', 'facture'])
            ->get();

        $toutes = $lignes
            ->map(fn (FactureLigne $ligne): array => [
                'facture_id' => $ligne->facture_id,
                'produit_id' => $ligne->produit_id,
                'nom' => $ligne->produit->nom,
                'categorie' => $ligne->produit->categorie->value,
                'quantite' => $ligne->quantite,
                'revenu' => round($ligne->quantite * (float) $ligne->prix_chf, 2),
                'cout' => round($ligne->quantite * (float) $ligne->produit->cout, 2),
                'clinique_id' => $ligne->facture->clinique_id,
                'jour' => $ligne->facture->payee_le->setTimezone($fuseau)->toDateString(),
            ]);
        // Les jours s'écrivent « aaaa-mm-jj » : ils se comparent comme des mots
        $ventes = $toutes->filter(fn (array $vente): bool => $vente['jour'] >= $debut->toDateString())->values();
        $avant = $toutes->filter(fn (array $vente): bool => $vente['jour'] < $debut->toDateString())->values();

        $totaux = $this->totaux($ventes);
        $precedent = $this->totaux($avant);

        // Servis aussi, l'écran ne les calcule pas (28.10.2026) : chiffre par
        // clinique, meilleures ventes (cinq, au chiffre d'affaires)
        $parClinique = $ventes->groupBy('clinique_id')
            ->map(fn ($lignes, $clinique): array => ['clinique_id' => (int) $clinique, 'chiffre_affaires' => round((float) $lignes->sum('revenu'), 2)])
            ->values();
        $top = $ventes->groupBy('produit_id')
            ->map(fn ($lignes, $produit): array => ['produit_id' => (int) $produit, 'nom' => $lignes->first()['nom'], 'quantite' => (int) $lignes->sum('quantite'), 'revenu' => round((float) $lignes->sum('revenu'), 2)])
            ->sortByDesc('revenu')->take(5)->values();
        $parFamille = $ventes->groupBy('categorie')
            ->map(fn ($lignes, $categorie): array => [
                'categorie' => $categorie,
                'chiffre_affaires' => round((float) $lignes->sum('revenu'), 2),
                'part_pct' => $totaux['chiffre_affaires'] > 0 ? (int) round($lignes->sum('revenu') / $totaux['chiffre_affaires'] * 100) : 0,
            ])
            ->sortByDesc('chiffre_affaires')->values();

        return response()->json(['data' => [
            'periode' => ['jours' => $periode, 'du' => $debut->toDateString(), 'au' => $aujourdhui->toDateString(), 'granularite' => $parMois ? 'mois' : 'jour'],
            'ventes' => $ventes,
            'serie' => $this->serie($ventes, $debut, $parMois ? 12 : $periode, $parMois),
            'par_clinique' => $parClinique,
            'par_famille' => $parFamille,
            'top' => $top,
            'totaux' => $totaux,
            'precedent' => collect($precedent)->only(['chiffre_affaires', 'tickets', 'panier_moyen', 'marge_pct'])->all(),
            'evolutions' => [
                'chiffre_affaires' => $this->evolution($totaux['chiffre_affaires'], $precedent['chiffre_affaires']),
                'tickets' => $this->evolution($totaux['tickets'], $precedent['tickets']),
                'panier_moyen' => $this->evolution($totaux['panier_moyen'], $precedent['panier_moyen']),
                'marge_pct' => $totaux['marge_pct'] === null || $precedent['marge_pct'] === null ? null : $totaux['marge_pct'] - $precedent['marge_pct'],
            ],
        ]]);
    }

    /** @return array{chiffre_affaires: float, cout: float, marge: float, tickets: int, panier_moyen: ?float, marge_pct: ?int} */
    private function totaux(Collection $ventes): array
    {
        $chiffreAffaires = round((float) $ventes->sum('revenu'), 2);
        $cout = round((float) $ventes->sum('cout'), 2);
        // Une vente, c'est un ticket (une facture), pas une ligne (28.10.2026)
        $tickets = $ventes->pluck('facture_id')->unique()->count();

        return [
            'chiffre_affaires' => $chiffreAffaires,
            'cout' => $cout,
            'marge' => round($chiffreAffaires - $cout, 2),
            'tickets' => $tickets,
            'panier_moyen' => $tickets > 0 ? round($chiffreAffaires / $tickets, 2) : null,
            'marge_pct' => $chiffreAffaires > 0 ? (int) round(($chiffreAffaires - $cout) / $chiffreAffaires * 100) : null,
        ];
    }

    /** Un créneau par jour (ou par mois), vide compris : « 2026-11-08 » ou « 2026-11 » */
    private function serie(Collection $ventes, Carbon $debut, int $creneaux, bool $parMois): array
    {
        $parCreneau = $ventes->groupBy(fn (array $vente): string => $parMois ? substr($vente['jour'], 0, 7) : $vente['jour']);

        return collect(range(0, $creneaux - 1))
            ->map(function (int $i) use ($debut, $parMois, $parCreneau): array {
                $cle = $parMois ? $debut->copy()->addMonthsNoOverflow($i)->format('Y-m') : $debut->copy()->addDays($i)->toDateString();

                return ['cle' => $cle, ...collect($this->totaux($parCreneau->get($cle, collect())))->only(['chiffre_affaires', 'tickets', 'panier_moyen', 'marge_pct'])->all()];
            })
            ->all();
    }

    /** L'évolution en pour cent, arrondie ; aucune sans point de départ (comme les statistiques de la caisse) */
    private function evolution(float|int|null $valeur, float|int|null $avant): ?int
    {
        if ($valeur === null || $avant === null || abs((float) $avant) < 0.005) {
            return null;
        }

        return (int) round(($valeur - $avant) / abs((float) $avant) * 100);
    }
}
