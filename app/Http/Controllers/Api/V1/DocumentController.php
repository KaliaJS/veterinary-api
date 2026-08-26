<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\EnregistrerDocumentRequest;
use App\Http\Resources\V1\DocumentResource;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Dossier documentaire : certificats, consentements, comptes rendus
 * PDF « archivés au dossier ». Métadonnées seulement pour l'instant —
 * jamais de suppression (l'archive fait foi).
 */
class DocumentController extends Controller
{
    public function index(Request $request, Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);

        return DocumentResource::collection(
            $patient->documents()
                ->orderByDesc('etabli_le')
                ->orderByDesc('id')
                ->paginate($this->parPage($request)),
        );
    }

    public function store(EnregistrerDocumentRequest $request, Patient $patient): DocumentResource
    {
        $this->authorize('update', $patient);

        $donnees = $request->validated();

        $document = $patient->documents()->create([
            ...$donnees,
            'etabli_le' => $donnees['etabli_le'] ?? today()->toDateString(),
        ]);

        return new DocumentResource($document);
    }
}
