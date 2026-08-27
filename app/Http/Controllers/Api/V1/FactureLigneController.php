<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\ModePrescription;
use App\Enums\TypeLigneFacture;
use App\Http\Controllers\Api\V1\Concerns\GesteDeCaisse;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\AjouterLigneFactureRequest;
use App\Http\Resources\V1\FactureResource;
use App\Models\Facture;
use App\Models\Prestation;
use App\Models\Produit;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ajout d'une ligne pendant l'encaissement (facture encore due, depuis
 * la clinique d'émission). Un acte s'ajoute au prix du catalogue ; un
 * médicament exige du stock local, s'ajoute au prix du produit et joint
 * la prescription « délivré par la clinique » — le stock ne sort qu'au
 * paiement (ou au « payer plus tard »).
 */
class FactureLigneController extends Controller
{
    use GesteDeCaisse;

    public function store(AjouterLigneFactureRequest $request, Facture $facture): FactureResource
    {
        $this->authorize('update', $facture);
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
}
