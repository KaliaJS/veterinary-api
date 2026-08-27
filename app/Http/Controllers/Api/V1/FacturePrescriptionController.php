<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ModePrescription;
use App\Http\Controllers\Api\V1\Concerns\GesteDeCaisse;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\AjouterPrescriptionFactureRequest;
use App\Http\Resources\V1\FactureResource;
use App\Models\Facture;
use App\Models\Produit;

/**
 * Prescription « à retirer en pharmacie » jointe à la facture — le
 * chemin de la rupture de stock : aucune ligne facturée, aucun stock.
 */
class FacturePrescriptionController extends Controller
{
    use GesteDeCaisse;

    public function store(AjouterPrescriptionFactureRequest $request, Facture $facture): FactureResource
    {
        $this->authorize('update', $facture);
        $this->assurerCliniqueDEmission($facture);
        $this->assurerFactureDue($facture);

        $produit = Produit::findOrFail($request->validated('produit_id'));

        $facture->prescriptions()->create([
            'nom' => $produit->nom,
            'posologie' => $request->validated('posologie') ?? 'Posologie à préciser',
            'mode' => ModePrescription::Pharmacie,
        ]);

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
    }
}
