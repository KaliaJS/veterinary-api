<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Site\TeleverserPhotoRequest;
use App\Http\Resources\V1\SiteWebResource;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\SiteWeb;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photo d'un employé, posée depuis l'éditeur du site (domaine
 * organisation) mais rangée sur la fiche : elle sert partout (site,
 * écran Équipe, planning). Fichier privé au nom imprévisible, servi par
 * une route publique en cache.
 */
class PhotoEmployeController extends Controller
{
    public function televerser(TeleverserPhotoRequest $request, int $employe): SiteWebResource
    {
        $this->authorize('update', SiteWeb::class);

        $fiche = $this->employeDeLOrganisation($employe);
        $ancien = $fiche->photo_chemin;

        $fiche->update(['photo_chemin' => $request->file('photo')->store('photos/'.$this->organisationId(), 'local')]);

        if ($ancien) {
            Storage::disk('local')->delete($ancien);
        }

        return new SiteWebResource(SiteWeb::courant());
    }

    public function supprimer(int $employe): SiteWebResource
    {
        $this->authorize('update', SiteWeb::class);

        $fiche = $this->employeDeLOrganisation($employe);

        if ($fiche->photo_chemin) {
            Storage::disk('local')->delete($fiche->photo_chemin);
            $fiche->update(['photo_chemin' => null]);
        }

        return new SiteWebResource(SiteWeb::courant());
    }

    public function afficher(string $fichier): StreamedResponse
    {
        $fiche = Employe::withoutGlobalScopes()->where('photo_chemin', 'like', '%/'.$fichier)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($fiche->photo_chemin), 404);

        return Storage::disk('local')->response($fiche->photo_chemin, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function organisationId(): int
    {
        return (int) app(ContexteClinique::class)->organisationId();
    }

    /** Un employé de l'organisation du jeton, où qu'il travaille. */
    private function employeDeLOrganisation(int $id): Employe
    {
        return Employe::withoutGlobalScope('clinique')->findOrFail($id);
    }
}
