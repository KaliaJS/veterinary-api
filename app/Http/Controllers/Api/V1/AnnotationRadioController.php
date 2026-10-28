<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Imagerie\AnnotationRadioRequest;
use App\Http\Resources\V1\AnnotationImagerieResource;
use App\Models\Patient;
use App\Support\Imagerie\RadiosDuDossier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Ce que le vétérinaire dessine sur une radio (28.10.2026) : gardé à part,
 * jamais dans le DICOM, en pixels de l'image d'origine, avec une étiquette
 * de la liste fixe, une certitude, son auteur et sa date. Lu et écrit par
 * l'équipe du dossier.
 */
class AnnotationRadioController extends Controller
{
    public function index(Patient $patient, int $examen, int $cliche): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);

        return AnnotationImagerieResource::collection(RadiosDuDossier::cliche($patient, $examen, $cliche)->annotations()->with('auteur')->orderBy('id')->get());
    }

    public function store(AnnotationRadioRequest $request, Patient $patient, int $examen, int $cliche): JsonResponse
    {
        $this->authorize('update', $patient);
        $annotation = RadiosDuDossier::cliche($patient, $examen, $cliche)->annotations()->create([
            ...$request->validated(),
            'image' => $request->validated('image', 0),
            'auteur_id' => $request->user()?->id,
        ]);

        return (new AnnotationImagerieResource($annotation->load('auteur')))->response()->setStatusCode(201);
    }

    public function update(AnnotationRadioRequest $request, Patient $patient, int $examen, int $cliche, int $annotation): AnnotationImagerieResource
    {
        $this->authorize('update', $patient);
        $dessin = RadiosDuDossier::cliche($patient, $examen, $cliche)->annotations()->findOrFail($annotation);
        $dessin->update($request->validated());

        return new AnnotationImagerieResource($dessin->load('auteur'));
    }

    public function destroy(Patient $patient, int $examen, int $cliche, int $annotation): Response
    {
        $this->authorize('update', $patient);
        RadiosDuDossier::cliche($patient, $examen, $cliche)->annotations()->findOrFail($annotation)->delete();

        return response()->noContent();
    }
}
