<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\ModePrescription;
use App\Enums\TypeLigneFacture;
use App\Http\Controllers\Api\V1\Concerns\GesteDeCaisse;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\AjouterLigneFactureRequest;
use App\Http\Requests\V1\Facturation\ModifierLigneFactureRequest;
use App\Http\Resources\V1\FactureResource;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Prestation;
use App\Models\Produit;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lignes d'une facture pendant l'encaissement (facture encore due,
 * depuis la clinique d'émission). Un acte s'ajoute au prix du
 * catalogue ; un médicament exige du stock local, s'ajoute au prix du
 * produit et joint la prescription « délivré par la clinique » — le
 * stock ne sort qu'au paiement (ou au « payer plus tard »). Une erreur
 * de saisie se corrige (quantité) ou se retire tant que la facture
 * n'est pas payée — même patron que les lignes d'un brouillon de
 * commande ; une ligne médicament dont le stock est déjà sorti
 * (« payer plus tard ») est figée, le journal de stock ferait foi.
 */
class FactureLigneController extends Controller
{
    use GesteDeCaisse;

    public function store(AjouterLigneFactureRequest $request, Facture $facture): FactureResource
    {
        $this->authorize('update', $facture);
        $this->sansPaiementEnCours($facture);
        $this->assurerCliniqueDEmission($facture);
        $this->assurerFactureDue($facture);

        $donnees = $request->validated();

        DB::transaction(function () use ($facture, $donnees): void {
            if (isset($donnees['prestation_id'])) {
                $prestation = Prestation::findOrFail($donnees['prestation_id']);

                $facture->lignes()->create([
                    'libelle' => $prestation->nom,
                    'quantite' => 1,
                    'prix_chf' => $prestation->prix,
                    'type' => TypeLigneFacture::Acte,
                ]);

                return;
            }

            $produit = Produit::findOrFail($donnees['produit_id']);

            $quantiteLocale = (int) Stock::query()
                ->where('produit_id', $produit->id)
                ->where('clinique_id', app(ContexteClinique::class)->cliniqueId())
                ->value('quantite');

            if ($quantiteLocale < 1) {
                throw ValidationException::withMessages([
                    'produit_id' => 'Rupture de stock — établissez une ordonnance pharmacie.',
                ]);
            }

            $facture->lignes()->create([
                'libelle' => $produit->nom,
                'quantite' => 1,
                'prix_chf' => $produit->prix,
                'type' => TypeLigneFacture::Medicament,
                'produit_id' => $produit->id,
            ]);

            $facture->prescriptions()->create([
                'nom' => $produit->nom,
                'posologie' => $donnees['posologie'] ?? 'Posologie à préciser',
                'mode' => ModePrescription::Stock,
            ]);
        });

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
    }

    public function update(ModifierLigneFactureRequest $request, Facture $facture, int $ligne): FactureResource
    {
        $ligne = $this->ligneModifiable($facture, $ligne);

        $ligne->update(['quantite' => $request->validated('quantite')]);

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
    }

    /**
     * Retrait d'une ligne ; la prescription jointe d'un médicament
     * part avec elle. Une facture garde au moins une ligne — une
     * facture vide se supprime (fenêtre d'undo), elle ne s'encaisse pas.
     */
    public function destroy(Facture $facture, int $ligne): FactureResource
    {
        $ligne = $this->ligneModifiable($facture, $ligne);

        if ($facture->lignes()->count() <= 1) {
            throw ValidationException::withMessages([
                'ligne' => 'Une facture garde au moins une ligne.',
            ]);
        }

        DB::transaction(function () use ($facture, $ligne): void {
            if ($ligne->type === TypeLigneFacture::Medicament) {
                $facture->prescriptions()
                    ->where('mode', ModePrescription::Stock)
                    ->where('nom', $ligne->libelle)
                    ->limit(1)
                    ->delete();
            }

            $ligne->delete();
        });

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
    }

    /**
     * Gardes communes aux corrections : facture due, clinique
     * d'émission, ligne de CETTE facture, et stock pas encore sorti
     * pour un médicament.
     *
     * @throws ValidationException
     */
    private function ligneModifiable(Facture $facture, int $id): FactureLigne
    {
        $this->authorize('update', $facture);
        $this->sansPaiementEnCours($facture);
        $this->assurerCliniqueDEmission($facture);
        $this->assurerFactureDue($facture);

        $ligne = $facture->lignes()->findOrFail($id);

        if ($ligne->type === TypeLigneFacture::Medicament && $facture->stock_sorti) {
            throw ValidationException::withMessages([
                'ligne' => 'Le stock de ce médicament est déjà sorti — corrigez par un ajustement de stock.',
            ]);
        }

        return $ligne;
    }

    /** La facture ne change pas pendant qu'un paiement Stripe attend le client (23.10.2026). */
    private function sansPaiementEnCours(Facture $facture): void
    {
        if ($facture->aUnPaiementEnCours()) {
            throw ValidationException::withMessages(['paiements' => 'Un paiement est en cours sur cette facture : annulez-le avant de la modifier.']);
        }
    }
}
