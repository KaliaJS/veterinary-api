<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\EnregistrerDocumentRequest;
use App\Http\Resources\V1\DocumentResource;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dossier documentaire : certificats, consentements, comptes rendus
 * PDF « archivés au dossier ». Un PDF peut s'y joindre (28.10.2026), gardé
 * tel quel sur le disque privé : le rapport ou le compte rendu d'un
 * confrère. Jamais de suppression (l'archive fait foi).
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
        $fichier = $request->file('fichier');
        unset($donnees['fichier']);

        $piece = [];
        if ($fichier !== null) {
            $chemin = Storage::disk('prive')->putFileAs('documents/'.$patient->organisation_id, $fichier, Str::uuid().'.pdf');
            abort_if($chemin === false, 500, 'Le PDF n’a pas pu être enregistré : réessayez.');
            $piece = [
                'chemin' => $chemin,
                'nom_fichier' => mb_substr($fichier->getClientOriginalName(), 0, 255),
                'taille_octets' => $fichier->getSize(),
                // Le rapport d'un confrère : montré au propriétaire seulement si la clinique le partage
                'visible_proprietaire' => $request->boolean('visible_proprietaire'),
            ];
        }

        $document = $patient->documents()->create([
            ...$donnees,
            ...$piece,
            // Le signataire est la personne connectée, jamais un nom saisi
            // (affiché dans l'espace du propriétaire), 24.10.2026 ; un PDF
            // ajouté n'est pas signé par elle : on garde qui l'a ajouté
            'signataire' => $fichier === null ? app(ContexteClinique::class)->employe()?->nom : null,
            'ajoute_par' => $request->user()?->id,
            'etabli_le' => $donnees['etabli_le'] ?? aujourdhui()->toDateString(),
        ]);

        return new DocumentResource($document->refresh());
    }

    /**
     * Fichier d'un document du dossier (consentement signé…), lu sur le
     * disque privé : jamais d'URL publique, le droit de voir le patient
     * suffit (23.10.2026).
     */
    public function fichier(Patient $patient, int $document): StreamedResponse
    {
        $this->authorize('view', $patient);

        $piece = $patient->documents()->findOrFail($document);
        abort_if($piece->chemin === null || ! Storage::disk('prive')->exists($piece->chemin), 404);

        $extension = pathinfo($piece->chemin, PATHINFO_EXTENSION);

        return Storage::disk('prive')->download($piece->chemin, Str::slug($piece->nom).($extension ? '.'.$extension : ''));
    }
}
