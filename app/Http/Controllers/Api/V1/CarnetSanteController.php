<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Espece;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Patients\ModifierPuceRequest;
use App\Http\Resources\V1\PatientResource;
use App\Models\Patient;
use App\Rules\NumeroPuce;
use Illuminate\Validation\ValidationException;

/**
 * Gestes du carnet de santé qui touchent la fiche : saisie de puce et
 * déclaration AMICUS (chiens, banque de données suisse — l'obligation
 * légale d'annonce exige une puce). La vraie intégration AMICUS est un
 * chantier à part : ici, l'horodatage de déclaration.
 */
class CarnetSanteController extends Controller
{
    public function modifierPuce(ModifierPuceRequest $request, Patient $patient): PatientResource
    {
        $this->authorize('updateIdentity', $patient);

        $patient->update(['puce' => NumeroPuce::formater($request->validated('puce'))]);

        return new PatientResource($patient);
    }

    public function declarerAmicus(Patient $patient): PatientResource
    {
        $this->authorize('updateIdentity', $patient);

        if ($patient->espece !== Espece::Chien) {
            throw ValidationException::withMessages([
                'espece' => 'Seuls les chiens se déclarent à AMICUS.',
            ]);
        }

        if ($patient->puce === null) {
            throw ValidationException::withMessages([
                'puce' => 'Déclaration impossible sans puce électronique.',
            ]);
        }

        if ($patient->amicus_declare_le === null) {
            $patient->update(['amicus_declare_le' => now()]);
        }

        return new PatientResource($patient);
    }
}
