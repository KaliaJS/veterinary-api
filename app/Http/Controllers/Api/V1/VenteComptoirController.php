<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\MoyenPaiement;
use App\Enums\TypeLigneFacture;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Boutique\ApercuVenteComptoirRequest;
use App\Http\Requests\V1\Boutique\VenteComptoirRequest;
use App\Http\Resources\V1\FactureResource;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CommandeBoutique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Produit;
use App\Models\Stock;
use App\Services\CreationFacture;
use App\Services\EncaissementFacture;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caisse comptoir de la boutique. La vente EST une facture payée de
 * lignes produit (la cible de docs/data-model.md §14 — pas d'entité
 * « vente » parallèle) : prix effectif vitrine, panier borné au stock
 * local, stock sorti à l'encaissement, mouvements tracés. Jamais un
 * produit sur ordonnance ni un stupéfiant (23.10.2026). En paiement
 * intégré, une vente par carte ou TWINT reste due jusqu'au paiement réussi
 * au terminal ou par le QR TWINT (28.10.2026). La facture
 * naît comme effet système du geste boutique (domaine stocks) — le
 * ticket est renvoyé au vendeur, la comptabilité reste au domaine
 * facturation.
 */
class VenteComptoirController extends Controller
{
    public function __construct(
        private CreationFacture $creation,
        private EncaissementFacture $encaissement,
    ) {}

    public function __invoke(VenteComptoirRequest $request): FactureResource
    {
        $this->authorize('create', CommandeBoutique::class);

        $donnees = $request->validated();

        $facture = DB::transaction(function () use ($request, $donnees) {
            // Rattachée à la fiche client choisie : son nom fait foi
            $client = isset($donnees['client_id']) ? Client::query()->find($donnees['client_id']) : null;
            $facture = $this->creation->nouvelle(
                ['client_id' => $client?->id, 'owner_name' => $client->nom_affiche ?? $donnees['client_nom'] ?? null],
                $this->lignes($donnees['lignes']),
                $request->user(),
            );

            // Paiement intégré, carte ou TWINT (décision de Jim, 28.10.2026) :
            // la vente attend le terminal ou le QR TWINT, comme une facture ;
            // le stock sort au paiement réussi
            $moyen = MoyenPaiement::from($donnees['moyen_paiement']);
            if ($moyen !== MoyenPaiement::Especes && app(ContexteClinique::class)->clinique()->encaisseAvecVeterinary()) {
                return $facture;
            }

            return $this->encaissement->encaisser($facture, $moyen, $request->user());
        });

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
    }

    /**
     * Le ticket avant l'encaissement (28.10.2026) : les mêmes lignes et le
     * même calcul que la facture (TTC, TVA au taux de la société, HT), sur
     * une facture jamais enregistrée. Rien n'est écrit, le stock ne bouge pas.
     */
    public function apercu(ApercuVenteComptoirRequest $request): JsonResponse
    {
        $this->authorize('create', CommandeBoutique::class);

        $lignes = $this->lignes($request->validated('lignes'));
        $facture = new Facture(['clinique_id' => app(ContexteClinique::class)->cliniqueId()]);
        $facture->taux_tva = Clinique::query()->with('societe')->find($facture->clinique_id)?->societe?->tauxTva() ?? Facture::TAUX_TVA * 100;
        $facture->setRelation('lignes', collect($lignes)->map(fn (array $ligne): FactureLigne => new FactureLigne($ligne)));

        return response()->json(['data' => [
            'lignes' => array_map(fn (array $ligne): array => [
                'produit_id' => $ligne['produit_id'],
                'quantite' => $ligne['quantite'],
                'prix_chf' => $ligne['prix_chf'],
                'total' => round($ligne['quantite'] * $ligne['prix_chf'], 2),
            ], $lignes),
            'total_ttc' => $facture->totalTtc(),
            'montant_tva' => $facture->montantTva(),
            'total_ht' => $facture->totalHt(),
            'taux_tva' => (float) $facture->taux_tva,
        ]]);
    }

    /**
     * Lignes de la vente : prix effectif vitrine, ni ordonnance ni
     * stupéfiant, bornées au stock de la clinique du jeton.
     *
     * @param  list<array{produit_id: int|string, quantite: int|string}>  $panier
     * @return list<array{libelle: string, quantite: int, prix_chf: float, type: TypeLigneFacture, produit_id: int}>
     *
     * @throws ValidationException
     */
    private function lignes(array $panier): array
    {
        $cliniqueId = app(ContexteClinique::class)->cliniqueId();
        $lignes = [];

        foreach ($panier as $index => $ligne) {
            $produit = Produit::findOrFail($ligne['produit_id']);

            // Ni ordonnance ni stupéfiant au comptoir
            if ($refus = $produit->refusVenteLibre()) {
                throw ValidationException::withMessages(["lignes.{$index}.produit_id" => $refus]);
            }

            $stockLocal = (int) Stock::query()
                ->where('produit_id', $produit->id)
                ->where('clinique_id', $cliniqueId)
                ->value('quantite');

            if ($stockLocal < (int) $ligne['quantite']) {
                throw ValidationException::withMessages([
                    "lignes.{$index}.quantite" => $stockLocal === 0
                        ? 'Rupture de stock : '.$produit->nom.'.'
                        : 'Stock maximum atteint : '.$stockLocal.' en rayon pour '.$produit->nom.'.',
                ]);
            }

            $lignes[] = [
                'libelle' => $produit->nom,
                'quantite' => (int) $ligne['quantite'],
                'prix_chf' => $produit->prixEffectif(),
                'type' => TypeLigneFacture::Medicament,
                'produit_id' => $produit->id,
            ];
        }

        return $lignes;
    }
}
