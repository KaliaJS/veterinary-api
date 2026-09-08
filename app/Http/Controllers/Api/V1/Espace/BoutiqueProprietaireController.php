<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Auth\SousContexteClinique;
use App\Enums\PieceNumerotee;
use App\Enums\StatutCommandeBoutique;
use App\Http\Controllers\Controller;
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
    public function __construct(private Numeroteur $numeroteur) {}

    public function vitrine(): JsonResponse
    {
        $cliniques = Clinique::withoutGlobalScopes()->with('societe.organisation')->orderBy('id')->get();

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
                        'categorie' => $produit->categorie?->value ?? $produit->categorie,
                        'prix' => $produit->prixEffectif(),
                        'prix_base' => (float) $produit->prix,
                        'promo_pct' => (int) $produit->promo_pct,
                        'est_vedette' => (bool) $produit->est_vedette,
                        'est_sur_ordonnance' => (bool) $produit->est_sur_ordonnance,
                        'stock' => $stock,
                        'disponible' => $stock > 0 && ! $produit->est_sur_ordonnance,
                    ];
                })->values()->all(),
            ];
        })->all()]);
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
                $clinique = Clinique::withoutGlobalScopes()->with('societe')->findOrFail((int) $panier['clinique_id']);
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

                        if ($produit->est_sur_ordonnance) {
                            throw ValidationException::withMessages([$champ => $produit->nom.' — en clinique uniquement (sur ordonnance).']);
                        }

                        $stockLocal = (int) Stock::query()->where('produit_id', $produit->id)->where('clinique_id', $clinique->id)->value('quantite');

                        if ($stockLocal < (int) $ligne['quantite']) {
                            throw ValidationException::withMessages([$champ => $stockLocal === 0
                                ? 'Rupture de stock — '.$produit->nom.' ('.$clinique->nom.').'
                                : 'Stock maximum atteint pour cette clinique — '.$stockLocal.' × '.$produit->nom.'.']);
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
            'passee_le' => $c->passee_le?->toIso8601String(),
            'retiree_le' => $c->retiree_le?->toIso8601String(),
            'clinique' => ['id' => $c->clinique->id, 'nom' => $c->clinique->nom, 'ville' => $c->clinique->ville],
            'articles' => $c->lignes->map(fn ($l) => ($l->produit?->nom ?? 'Article').($l->quantite > 1 ? ' ×'.$l->quantite : ''))->implode(' · '),
            'lignes' => $c->lignes->map(fn ($l): array => ['produit' => $l->produit?->nom, 'quantite' => $l->quantite, 'prix_chf' => (float) $l->prix_chf])->values()->all(),
            'total_ttc' => round($c->totalTtc(), 2),
        ];
    }
}
