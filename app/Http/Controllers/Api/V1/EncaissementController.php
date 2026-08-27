<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MoyenPaiement;
use App\Http\Controllers\Api\V1\Concerns\GesteDeCaisse;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\EncaisserFactureRequest;
use App\Http\Resources\V1\FactureResource;
use App\Models\Facture;
use App\Services\EncaissementFacture;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Encaissement (docs/data-model.md §6) : TPE, TWINT ou espèces (montant
 * reçu contrôlé), depuis la clinique d'émission. « Payer plus tard »
 * laisse la facture due mais le stock sort quand même.
 */
class EncaissementController extends Controller
{
    use GesteDeCaisse;

    public function __construct(private EncaissementFacture $encaissement) {}

    public function encaisser(EncaisserFactureRequest $request, Facture $facture): FactureResource
    {
        $this->authorize('update', $facture);
        $this->assurerCliniqueDEmission($facture);
        $this->assurerFactureDue($facture);

        $moyen = MoyenPaiement::from($request->validated('moyen_paiement'));

        if ($moyen === MoyenPaiement::Especes && $request->filled('montant_recu')) {
            $facture->loadMissing('lignes');

            if ((float) $request->validated('montant_recu') < $facture->totalTtc()) {
                throw ValidationException::withMessages([
                    'montant_recu' => 'Montant reçu insuffisant.',
                ]);
            }
        }

        $this->encaissement->encaisser($facture, $moyen, $request->user());

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
    }

    public function payerPlusTard(Request $request, Facture $facture): FactureResource
    {
        $this->authorize('update', $facture);
        $this->assurerCliniqueDEmission($facture);
        $this->assurerFactureDue($facture);

        $this->encaissement->payerPlusTard($facture, $request->user());

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
    }
}
