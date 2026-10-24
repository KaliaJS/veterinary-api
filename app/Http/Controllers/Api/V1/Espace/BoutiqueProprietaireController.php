<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Auth\SousContexteClinique;
use App\Enums\PieceNumerotee;
use App\Enums\ServiceClinique;
use App\Enums\StatutCommandeBoutique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\ApercuPanierRequest;
use App\Http\Requests\V1\Espace\PasserCommandesRequest;
use App\Models\Clinique;
use App\Models\CommandeBoutique;
use App\Models\Produit;
use App\Models\Stock;
use App\Services\Numeroteur;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Boutiques des cliniques (proto « Les boutiques des cliniques ») :
 * chaque clinique vend son propre stock — vitrine = produits visibles
 * en boutique de son organisation, avec la quantité en rayon de CETTE
 * clinique. Le panier passe une commande PAR clinique de retrait
 * (numéro de l'organisation, fiche client du compte si elle existe) ;
 * la quantité commandée est bornée par le stock local, la sortie de
 * stock n'a lieu qu'à la remise (geste de clinique). Mes commandes :
 * celles des fiches du compte.
 */
class BoutiqueProprietaireController extends Controller
{
    /** Le stock montré au public, plafonné. */
    public const int STOCK_AFFICHE_MAX = 10;

    public function __construct(private Numeroteur $numeroteur) {}

    public function vitrine(): JsonResponse
    {
        // Seules les cliniques qui ont la boutique (service coché par l'administrateur)
        $cliniques = Clinique::withoutGlobalScopes()->ouvertes()->avecService(ServiceClinique::Boutique)->with('societe.organisation')->orderBy('id')->get();

        $produitsParOrganisation = Produit::withoutGlobalScopes()
            ->where('est_boutique', true)
            ->orderBy('nom')
            ->get()
            ->groupBy('organisation_id');

        $stocks = Stock::withoutGlobalScopes()->get()->groupBy('clinique_id');

        return response()->json(['data' => $cliniques->map(function (Clinique $clinique) use ($produitsParOrganisation, $stocks): array {
            $organisation = $clinique->societe->organisation;
            $stockLocal = ($stocks[$clinique->id] ?? collect())->keyBy('produit_id');

            return [
                'id' => $clinique->id,
                'nom' => $clinique->nom,
                'ville' => $clinique->ville,
                'organisation' => ['id' => $organisation->id, 'nom' => $organisation->nom],
                'produits' => ($produitsParOrganisation[$organisation->id] ?? collect())->map(function (Produit $produit) use ($stockLocal): array {
                    $stock = (int) ($stockLocal[$produit->id]->quantite ?? 0);

                    return [
                        'id' => $produit->id,
                        'nom' => $produit->nom,
                        'categorie' => $produit->categorie->value,
                        'prix' => $produit->prixEffectif(),
                        'prix_base' => (float) $produit->prix,
                        'promo_pct' => (int) $produit->promo_pct,
                        'est_vedette' => (bool) $produit->est_vedette,
                        'est_sur_ordonnance' => (bool) $produit->est_sur_ordonnance,
                        // Au-delà de quelques pièces, « en stock » suffit : le stock
                        // exact d'une clinique ne regarde qu'elle (23.10.2026)
                        'stock' => min($stock, self::STOCK_AFFICHE_MAX),
                        'disponible' => $stock > 0 && ! $produit->refusVenteLibre(),
                    ];
                })->values()->all(),
            ];
        })->all()]);
    }

    /**
     * Le panier chiffré par le serveur (24.10.2026) : par clinique, le
     * total, la TVA au taux de sa société (rien si elle n'y est pas
     * assujettie) et le hors taxes ; l'écran les affiche sans calcul. Les
     * prix sont ceux du moment (promotion comprise) ; le stock se contrôle
     * à la commande.
     */
    public function apercu(ApercuPanierRequest $request): JsonResponse
    {
        $paniers = collect($request->validated('commandes'));
        $cliniques = Clinique::withoutGlobalScopes()->ouvertes()->avecService(ServiceClinique::Boutique)->with('societe')
            ->whereIn('id', $paniers->pluck('clinique_id'))->get()->keyBy('id');
        $produits = Produit::withoutGlobalScopes()->where('est_boutique', true)
            ->whereIn('id', $paniers->flatMap(fn (array $p) => array_column($p['lignes'], 'produit_id')))->get()->keyBy('id');

        $parClinique = $paniers->map(function (array $panier) use ($cliniques, $produits): ?array {
            $clinique = $cliniques[(int) $panier['clinique_id']] ?? null;
            if (! $clinique) {
                return null;
            }
            // Le total de chaque ligne servi aussi : l'écran n'en calcule aucun ;
            // un article retiré de la vitrine ou d'une autre organisation n'est
            // pas chiffré, l'écran le dit indisponible (24.10.2026)
            $lignes = collect($panier['lignes'])->map(function (array $ligne) use ($produits, $clinique): ?array {
                $produit = $produits[(int) $ligne['produit_id']] ?? null;
                if (! $produit || $produit->organisation_id !== $clinique->societe->organisation_id) {
                    return null;
                }

                return ['produit_id' => (int) $ligne['produit_id'], 'quantite' => (int) $ligne['quantite'], 'total' => round((int) $ligne['quantite'] * $produit->prixEffectif(), 2)];
            })->filter()->values();
            $ttc = round((float) $lignes->sum('total'), 2);
            $taux = $clinique->societe->tauxTva();
            $tva = $taux > 0 ? round($ttc - $ttc / (1 + $taux / 100), 2) : 0.0;

            return ['clinique_id' => $clinique->id, 'lignes' => $lignes->all(), 'total_ttc' => $ttc, 'montant_tva' => $tva, 'total_ht' => round($ttc - $tva, 2), 'taux_tva' => $taux];
        })->filter()->values();

        return response()->json(['data' => [
            'cliniques' => $parClinique->all(),
            'total_ttc' => round((float) $parClinique->sum('total_ttc'), 2),
            'montant_tva' => round((float) $parClinique->sum('montant_tva'), 2),
            'total_ht' => round((float) $parClinique->sum('total_ht'), 2),
            // Un seul taux affiché quand toutes les cliniques ont le même
            'taux_tva' => $parClinique->pluck('taux_tva')->unique()->count() === 1 ? $parClinique->first()['taux_tva'] : null,
        ]]);
    }

    public function commandes(ContexteProprietaire $contexte): JsonResponse
    {
        $commandes = $contexte->clientIds() === []
            ? collect()
            : CommandeBoutique::withoutGlobalScopes()
                ->with(['clinique', 'lignes.produit'])
                ->whereIn('client_id', $contexte->clientIds())
                ->orderByDesc('passee_le')
                ->orderByDesc('id')
                ->get();

        return response()->json(['data' => $commandes->map(fn (CommandeBoutique $c): array => $this->commande($c))->all()]);
    }

    public function passer(PasserCommandesRequest $request, ContexteProprietaire $contexte, SousContexteClinique $sousContexte): JsonResponse
    {
        $donnees = $request->validated();
        $user = $contexte->user();

        $commandes = DB::transaction(function () use ($donnees, $contexte, $sousContexte, $user): array {
            $creees = [];

            foreach ($donnees['commandes'] as $indexCommande => $panier) {
                $clinique = Clinique::withoutGlobalScopes()->ouvertes()->avecService(ServiceClinique::Boutique)->with('societe')->findOrFail((int) $panier['clinique_id']);
                $fiche = $contexte->clients()->firstWhere('organisation_id', $clinique->societe->organisation_id);
                $nom = trim((string) ($donnees['nom'] ?? '')) ?: ($fiche ? trim($fiche->prenom.' '.$fiche->nom) : $user->name);

                $creees[] = $sousContexte->pour($clinique, function () use ($panier, $indexCommande, $clinique, $fiche, $nom): CommandeBoutique {
                    $commande = CommandeBoutique::create([
                        'numero' => $this->numeroteur->attribuer(PieceNumerotee::CommandeBoutique),
                        'client_id' => $fiche?->id,
                        'client_nom' => $nom,
                        'statut' => StatutCommandeBoutique::APreparer,
                        'passee_le' => now(),
                    ]);

                    foreach ($panier['lignes'] as $indexLigne => $ligne) {
                        $produit = Produit::find((int) $ligne['produit_id']);
                        $champ = "commandes.{$indexCommande}.lignes.{$indexLigne}.quantite";

                        if (! $produit || ! $produit->est_boutique) {
                            throw ValidationException::withMessages([$champ => 'Seul un article visible en boutique se commande en ligne.']);
                        }

                        if ($produit->refusVenteLibre()) {
                            throw ValidationException::withMessages([$champ => $produit->nom.' : en clinique uniquement, sur ordonnance.']);
                        }

                        $stockLocal = (int) Stock::query()->where('produit_id', $produit->id)->where('clinique_id', $clinique->id)->value('quantite');

                        if ($stockLocal < (int) $ligne['quantite']) {
                            // Jamais le stock exact (24.10.2026), comme la vitrine
                            throw ValidationException::withMessages([$champ => $stockLocal === 0
                                ? 'Rupture de stock : '.$produit->nom.' ('.$clinique->nom.').'
                                : 'Pas assez de stock pour cette quantité de '.$produit->nom.' chez '.$clinique->nom.' : réduisez-la.']);
                        }

                        $commande->lignes()->create([
                            'produit_id' => $produit->id,
                            'quantite' => (int) $ligne['quantite'],
                            'prix_chf' => $produit->prixEffectif(),
                        ]);
                    }

                    return $commande->load(['clinique', 'lignes.produit']);
                });
            }

            return $creees;
        });

        return response()->json(['data' => array_map(fn (CommandeBoutique $c): array => $this->commande($c), $commandes)], 201);
    }

    /** @return array<string, mixed> */
    private function commande(CommandeBoutique $c): array
    {
        return [
            'id' => $c->id,
            'numero' => $c->numero,
            'statut' => $c->statut->value,
            'statut_libelle' => match ($c->statut) {
                StatutCommandeBoutique::APreparer => 'En préparation',
                StatutCommandeBoutique::Prete => 'Prête au retrait',
                StatutCommandeBoutique::Retiree => 'Retirée',
            },
            'passee_le' => $c->passee_le->toIso8601String(),
            'retiree_le' => $c->retiree_le?->toIso8601String(),
            'clinique' => ['id' => $c->clinique->id, 'nom' => $c->clinique->nom, 'ville' => $c->clinique->ville],
            'articles' => $c->lignes->map(fn ($l) => ($l->produit->nom ?? 'Article').($l->quantite > 1 ? ' ×'.$l->quantite : ''))->implode(' · '),
            'lignes' => $c->lignes->map(fn ($l): array => ['produit' => $l->produit?->nom, 'quantite' => $l->quantite, 'prix_chf' => (float) $l->prix_chf])->values()->all(),
            'total_ttc' => round($c->totalTtc(), 2),
        ];
    }
}
