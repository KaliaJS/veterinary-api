<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Site\TeleverserPhotoRequest;
use App\Http\Resources\V1\PatientResource;
use App\Models\Patient;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photo de l'animal (16.10.2026), sur le modèle de la photo des employés :
 * fichier privé au nom imprévisible sur le disque local, servi par une
 * route publique en cache (une balise image ne porte pas de jeton). La
 * photo fait partie de l'identité : même droit que le nom ou la puce
 * (PatientPolicy::updateIdentity).
 */
class PhotoPatientController extends Controller
{
    public function televerser(TeleverserPhotoRequest $request, Patient $patient): PatientResource
    {
        $this->authorize('updateIdentity', $patient);

        $ancien = $patient->photo_chemin;
        $patient->update(['photo_chemin' => $request->file('photo')->store('photos-animaux/'.$patient->organisation_id, 'local')]);

        if ($ancien) {
            Storage::disk('local')->delete($ancien);
        }

        return new PatientResource($patient->load('client'));
    }

    public function supprimer(Patient $patient): PatientResource
    {
        $this->authorize('updateIdentity', $patient);

        if ($patient->photo_chemin) {
            Storage::disk('local')->delete($patient->photo_chemin);
            $patient->update(['photo_chemin' => null]);
        }

        return new PatientResource($patient->load('client'));
    }

    public function afficher(string $fichier): StreamedResponse
    {
        $patient = Patient::withoutGlobalScopes()->where('photo_chemin', 'like', '%/'.$fichier)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($patient->photo_chemin), 404);

        return Storage::disk('local')->response($patient->photo_chemin, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
