<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Imagerie\EnvoyerRadioRequest;
use App\Http\Resources\V1\ClicheDicomResource;
use App\Models\ClicheDicom;
use App\Models\ExamenImagerie;
use App\Models\Patient;
use App\Support\Dicom\LecteurDicom;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Une radio DICOM envoyée depuis l'examen (décision de Jim, 28.10.2026) :
 * l'original est gardé tel quel sur le disque privé, ni compressé ni
 * modifié (empreinte SHA-256). L'espèce, la race et le poids de l'animal
 * du jour y sont rattachés ; la région et l'incidence sont pré-remplies
 * depuis le DICOM quand il les porte sans ambiguïté, à valider par un
 * humain. Le même cliché envoyé deux fois n'est ni doublé ni remplacé.
 */
class RadioController extends Controller
{
    public function __invoke(EnvoyerRadioRequest $request, ExamenImagerie $examen): JsonResponse
    {
        $this->authorize('update', $examen);
        if ($examen->estSigne()) {
            throw ValidationException::withMessages(['fichier' => 'Le compte rendu est signé : l’examen ne reçoit plus de radios.']);
        }

        $fichier = $request->file('fichier');
        $lu = LecteurDicom::lire((string) $fichier->getRealPath());
        if ($lu === null || $lu['sop_instance_uid'] === null || ! $lu['pixels']) {
            throw ValidationException::withMessages(['fichier' => 'Ce fichier n’est pas une image DICOM.']);
        }
        // Un UID DICOM : chiffres et points, 64 caractères au plus (PS3.5 §9.1)
        if (strlen($lu['sop_instance_uid']) > 64 || preg_match('/^[0-9]+(\.[0-9]+)*$/', $lu['sop_instance_uid']) !== 1) {
            throw ValidationException::withMessages(['fichier' => 'L’identifiant de cette image DICOM est invalide : le fichier vient-il bien d’un appareil de radiographie ?']);
        }

        // La même radio dans une autre organisation (un référé) : un autre cliché, rien n'est révélé
        $organisation = $examen->clinique()->withoutGlobalScopes()->firstOrFail()->societe()->value('organisation_id');
        $existant = ClicheDicom::query()->where('sop_instance_uid', $lu['sop_instance_uid'])
            ->whereHas('examen', fn ($e) => $e->withoutGlobalScopes()->whereHas('clinique', fn ($c) => $c->withoutGlobalScopes()->whereHas('societe', fn ($s) => $s->where('organisation_id', $organisation))))
            ->first();
        if ($existant !== null && $existant->examen_imagerie_id !== $examen->id) {
            throw ValidationException::withMessages(['fichier' => 'Cette radio appartient déjà à un autre examen de l’organisation.']);
        }
        // Déjà reçu : l'original n'est jamais remplacé
        if ($existant?->taille_octets !== null) {
            return (new ClicheDicomResource($existant))->response()->setStatusCode(200);
        }

        $patient = Patient::withoutGlobalScopes()->findOrFail($examen->patient_id);
        $dossier = 'imagerie/'.$organisation.'/'.$examen->id;
        $nom = $lu['sop_instance_uid'].'.dcm';
        $chemin = Storage::disk('prive')->putFileAs($dossier, $fichier, $nom);
        if ($chemin === false) {
            throw ValidationException::withMessages(['fichier' => 'La radio n’a pas pu être enregistrée : réessayez.']);
        }

        $regions = config('imagerie.depuis_dicom.regions');
        $incidences = config('imagerie.depuis_dicom.incidences');
        $cliche = $existant ?? new ClicheDicom(['examen_imagerie_id' => $examen->id, 'sop_instance_uid' => $lu['sop_instance_uid']]);
        $cliche->fill([
            'study_instance_uid' => $lu['study_instance_uid'],
            'series_instance_uid' => $lu['series_instance_uid'],
            'nom_fichier' => mb_substr($fichier->getClientOriginalName(), 0, 255),
            'chemin' => $chemin,
            'taille_octets' => $fichier->getSize(),
            'empreinte_sha256' => hash_file('sha256', (string) $fichier->getRealPath()),
            'syntaxe_transfert' => $lu['syntaxe_transfert'],
            'modalite_dicom' => $lu['modalite'],
            'lignes' => $lu['lignes'],
            'colonnes' => $lu['colonnes'],
            'images' => $lu['images'],
            'espece' => $patient->espece->value,
            'race' => $patient->race,
            // La dernière pesée faite au plus tard le jour de la radio
            'poids_kg' => $patient->pesees()->whereDate('pesee_le', '<=', $examen->realise_le)->orderByDesc('pesee_le')->orderByDesc('id')->value('poids_kg'),
            'region' => $regions[mb_strtoupper((string) $lu['body_part'])] ?? null,
            'incidence' => $incidences[mb_strtoupper((string) $lu['view_position'])] ?? null,
            'reperes_dicom' => ['body_part' => $lu['body_part'], 'view_position' => $lu['view_position'], 'lateralite' => $lu['lateralite']],
            'envoye_par' => $request->user()?->id,
        ])->save();

        return (new ClicheDicomResource($cliche->refresh()))->response()->setStatusCode($existant === null ? 201 : 200);
    }
}
