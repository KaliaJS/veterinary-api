<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\MoyenPaiement;
use App\Enums\TypeLigneFacture;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Boutique\VenteComptoirRequest;
use App\Http\Resources\V1\FactureResource;
use App\Models\CommandeBoutique;
use App\Models\Produit;
use App\Models\Stock;
use App\Services\CreationFacture;
use App\Services\EncaissementFacture;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caisse comptoir de la boutique. La vente EST une facture payée de
 * lignes produit (la cible de docs/data-model.md §14 — pas d'entité
 * « vente » parallèle) : prix effectif vitrine, panier borné au stock
 * local, stock sorti à l'encaissement, mouvements tracés. Jamais un
 * produit sur ordonnance ni un stupéfiant (23.10.2026). La facture
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
        $cliniqueId = app(ContexteClinique::class)->cliniqueId();

        $facture = DB::transaction(function () use ($request, $donnees, $cliniqueId) {
            $lignes = [];

            foreach ($donnees['lignes'] as $index => $ligne) {
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

            $facture = $this->creation->nouvelle(
                ['owner_name' => $donnees['client_nom'] ?? null],
                $lignes,
                $request->user(),
            );

            return $this->encaissement->encaisser(
                $facture,
                MoyenPaiement::from($donnees['moyen_paiement']),
                $request->user(),
            );
        });

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
    }
}
