<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Organisation\TeleverserPhotoCliniqueRequest;
use App\Http\Resources\V1\CliniqueResource;
use App\Models\Clinique;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photo d'une clinique, posée par l'administrateur de l'organisation
 * (écran Organisation) : elle remplace la vue aérienne dans l'annuaire
 * des cliniques. Fichier privé au nom imprévisible, servi par une route
 * publique en cache.
 */
class PhotoCliniqueController extends Controller
{
    public function televerser(TeleverserPhotoCliniqueRequest $request, int $clinique): CliniqueResource
    {
        $fiche = $this->cliniqueDeLOrganisation($clinique);
        $ancien = $fiche->photo_chemin;

        $fiche->update(['photo_chemin' => $request->file('photo')->store('photos-cliniques/'.$this->organisationId(), 'local')]);

        if ($ancien) {
            Storage::disk('local')->delete($ancien);
        }

        return new CliniqueResource($fiche);
    }

    public function supprimer(int $clinique): CliniqueResource
    {
        $fiche = $this->cliniqueDeLOrganisation($clinique);

        if ($fiche->photo_chemin) {
            Storage::disk('local')->delete($fiche->photo_chemin);
            $fiche->update(['photo_chemin' => null]);
        }

        return new CliniqueResource($fiche);
    }

    public function afficher(string $fichier): StreamedResponse
    {
        $fiche = Clinique::query()->where('photo_chemin', 'like', '%/'.$fichier)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($fiche->photo_chemin), 404);

        return Storage::disk('local')->response($fiche->photo_chemin, null, [
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
