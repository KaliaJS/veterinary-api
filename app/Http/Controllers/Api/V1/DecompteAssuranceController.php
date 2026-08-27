<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutDecompteAssurance;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\EnregistrerDecompteAssuranceRequest;
use App\Http\Resources\V1\DecompteAssuranceResource;
use App\Models\Facture;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Décompte d'assurance d'une facture (0..1) : affectation de
 * l'assureur, puis avancée de la demande — à envoyer → envoyée →
 * remboursée, horodatée. La lecture reste possible de toute
 * l'organisation (le décompte suit la facture).
 */
class DecompteAssuranceController extends Controller
{
    public function enregistrer(EnregistrerDecompteAssuranceRequest $request, Facture $facture): DecompteAssuranceResource
    {
        $this->authorize('update', $facture);

        $decompte = $facture->decompte()->updateOrCreate(
            [],
            ['assureur' => $request->validated('assureur')],
        );

        return new DecompteAssuranceResource($decompte);
    }

    public function avancer(Request $request, Facture $facture): DecompteAssuranceResource
    {
        $this->authorize('update', $facture);

        $decompte = $facture->decompte;

        if ($decompte === null) {
            throw ValidationException::withMessages([
                'decompte' => 'Affectez d’abord un assureur à la facture.',
            ]);
        }

        $decompte->update(match ($decompte->statut) {
            StatutDecompteAssurance::AEnvoyer => [
                'statut' => StatutDecompteAssurance::Envoyee,
                'envoye_le' => now(),
            ],
            StatutDecompteAssurance::Envoyee => [
                'statut' => StatutDecompteAssurance::Remboursee,
                'rembourse_le' => now(),
            ],
            StatutDecompteAssurance::Remboursee => throw ValidationException::withMessages([
                'decompte' => 'Le remboursement est déjà encaissé.',
            ]),
        });

        return new DecompteAssuranceResource($decompte);
    }
}
