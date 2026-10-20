<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\TeleverserPhotoCliniqueRequest;
use App\Http\Resources\V1\CliniqueResource;
use App\Models\Clinique;
use App\Services\Photos\PhotosCliniques;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photo d'une clinique, posée par l'administrateur de l'organisation
 * (écran Organisation) : obligatoire pour paraître dans l'annuaire des
 * cliniques. Versions WebP et aperçu flou tirés à l'envoi
 * (PhotosCliniques). Fichiers privés aux noms imprévisibles, servis par
 * une route publique en cache.
 */
class PhotoCliniqueController extends Controller
{
    public function televerser(TeleverserPhotoCliniqueRequest $request, int $clinique, PhotosCliniques $photos): CliniqueResource
    {
        $fiche = $this->cliniqueDeLOrganisation($clinique);
        $ancien = $fiche->photo_chemin;

        $nouvelle = $photos->enregistrer($request->file('photo'), $this->organisationId());
        $fiche->update(['photo_chemin' => $nouvelle['chemin'], 'photo_apercu' => $nouvelle['apercu']]);
        $photos->supprimer($ancien);

        return new CliniqueResource($fiche);
    }

    public function supprimer(int $clinique, PhotosCliniques $photos): CliniqueResource
    {
        $fiche = $this->cliniqueDeLOrganisation($clinique);

        $photos->supprimer($fiche->photo_chemin);
        $fiche->update(['photo_chemin' => null, 'photo_apercu' => null]);

        return new CliniqueResource($fiche);
    }

    /** L'originale ou une de ses versions (« …-480.webp »), rangées côte à côte. */
    public function afficher(string $fichier): StreamedResponse
    {
        $base = preg_replace('/(-\d+)?\.[a-z]+$/', '', $fichier);
        $fiche = Clinique::query()->where('photo_chemin', 'like', '%/'.$base.'.%')->firstOrFail();
        $chemin = dirname($fiche->photo_chemin).'/'.$fichier;
        abort_unless(Storage::disk('local')->exists($chemin), 404);

        return Storage::disk('local')->response($chemin, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function organisationId(): int
    {
        return (int) app(ContexteClinique::class)->organisationId();
    }

    private function cliniqueDeLOrganisation(int $id): Clinique
    {
        return app(ContexteClinique::class)->clinique()->societe->organisation->cliniques()->findOrFail($id);
    }
}
