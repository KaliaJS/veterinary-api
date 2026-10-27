<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Site\TeleverserPhotoRequest;
use App\Http\Resources\V1\PatientResource;
use App\Models\Patient;
use App\Services\Photos\ImageReencodee;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photo de l'animal (16.10.2026), sur le modèle de la photo des employés :
 * fichier privé au nom imprévisible sur le disque local, servi par une
 * route publique, en cache du navigateur seulement (une balise image ne
 * porte pas de jeton). La
 * photo fait partie de l'identité : même droit que le nom ou la puce
 * (PatientPolicy::updateIdentity).
 */
class PhotoPatientController extends Controller
{
    public function televerser(TeleverserPhotoRequest $request, Patient $patient): PatientResource
    {
        $this->authorize('updateIdentity', $patient);

        $ancien = $patient->photo_chemin;
        // Réécrite en WebP, sans les données de l'appareil (23.10.2026)
        $patient->update(['photo_chemin' => ImageReencodee::enregistrer($request->file('photo'), 'photos-animaux/'.$patient->organisation_id, 1200)]);

        if ($ancien) {
            Storage::disk('prive')->delete($ancien);
        }

        return new PatientResource($patient->load('client'));
    }

    public function supprimer(Patient $patient): PatientResource
    {
        $this->authorize('updateIdentity', $patient);

        if ($patient->photo_chemin) {
            Storage::disk('prive')->delete($patient->photo_chemin);
            $patient->update(['photo_chemin' => null]);
        }

        return new PatientResource($patient->load('client'));
    }

    public function afficher(string $fichier): StreamedResponse
    {
        $patient = Patient::withoutGlobalScopes()->where('photo_fichier', pathinfo($fichier, PATHINFO_FILENAME))->firstOrFail();
        abort_unless(basename((string) $patient->photo_chemin) === $fichier && Storage::disk('prive')->exists($patient->photo_chemin), 404);

        // Le navigateur la garde ; un cache partagé (bord de Cloudflare) jamais :
        // une photo supprimée ou un accord retiré n'y survit pas (27.10.2026)
        return Storage::disk('prive')->response($patient->photo_chemin, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
