<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutDevis;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FactureResource;
use App\Models\Devis;
use App\Models\Facture;
use App\Services\CreationFacture;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Facturation d'un devis accepté : acompte (une ligne au pourcentage
 * demandé) puis facture finale (lignes recopiées, remise et acompte
 * déduits en négatif). Chacune ne se crée qu'une fois ; la facture est
 * émise par la clinique du jeton et s'encaisse en caisse.
 */
class FactureDevisController extends Controller
{
    public function __construct(private CreationFacture $creation) {}

    public function acompte(Request $request, Devis $devis): FactureResource
    {
        $this->authorize('create', Facture::class);
        $this->assurerAccepte($devis);

        if ($devis->acompte_pct < 1) {
            throw ValidationException::withMessages(['acompte' => 'Ce devis ne demande aucun acompte.']);
        }

        if ($devis->acompte_facture_id !== null) {
            throw ValidationException::withMessages(['acompte' => 'L’acompte est déjà facturé — '.$devis->acompteFacture->numero.'.']);
        }

        $facture = $this->creation->acompteDeDevis($devis, $request->user());

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
    }

    public function finale(Request $request, Devis $devis): FactureResource
    {
        $this->authorize('create', Facture::class);
        $this->assurerAccepte($devis);

        if ($devis->facture_finale_id !== null) {
            throw ValidationException::withMessages(['facture' => 'La facture finale existe déjà — '.$devis->factureFinale->numero.'.']);
        }

        $facture = $this->creation->finaleDeDevis($devis, $request->user());

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
    }

    private function assurerAccepte(Devis $devis): void
    {
        if ($devis->statut !== StatutDevis::Accepte) {
            throw ValidationException::withMessages(['statut' => 'Seul un devis accepté se facture.']);
        }
    }
}
