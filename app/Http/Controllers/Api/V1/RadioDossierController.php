<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Imagerie\ReperesRadioRequest;
use App\Http\Resources\V1\ClicheDicomResource;
use App\Models\Patient;
use App\Support\Imagerie\RadiosDuDossier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Une radio du dossier (28.10.2026) : son original, relu tel quel par la
 * visionneuse ; sa région et son incidence, validées par un humain.
 * Résolue par le patient : toute l'équipe du dossier (cliniques sœurs
 * comprises), jamais une autre organisation.
 */
class RadioDossierController extends Controller
{
    public function fichier(Patient $patient, int $examen, int $cliche): StreamedResponse
    {
        $this->authorize('view', $patient);
        $radio = RadiosDuDossier::cliche($patient, $examen, $cliche);
        abort_if($radio->taille_octets === null || $radio->chemin === null || ! Storage::disk('prive')->exists($radio->chemin), 404);

        return Storage::disk('prive')->download($radio->chemin, $radio->sop_instance_uid.'.dcm', ['Content-Type' => 'application/dicom', 'Cache-Control' => 'private, max-age=86400']);
    }

    public function reperes(ReperesRadioRequest $request, Patient $patient, int $examen, int $cliche): ClicheDicomResource
    {
        $this->authorize('update', $patient);
        $radio = RadiosDuDossier::cliche($patient, $examen, $cliche);
        $radio->fill($request->validated())->forceFill(['reperes_valides_par' => $request->user()?->id, 'reperes_valides_le' => now()])->save();

        return new ClicheDicomResource($radio->loadCount('annotations'));
    }

    public function index(Request $request, Patient $patient, int $examen): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);

        return ClicheDicomResource::collection(RadiosDuDossier::examen($patient, $examen)->cliches()->withCount('annotations')->get());
    }
}
