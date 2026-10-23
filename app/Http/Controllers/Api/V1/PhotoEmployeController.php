<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Site\TeleverserPhotoRequest;
use App\Http\Resources\V1\SiteWebResource;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\SiteWeb;
use App\Services\Photos\ImageReencodee;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photo d'un employé, posée depuis l'éditeur du site (domaine
 * organisation) mais rangée sur la fiche : elle sert partout (site,
 * écran Équipe, planning). Fichier privé au nom imprévisible, réécrit en
 * WebP sans les données de l'appareil, servi par une route publique en
 * cache, seulement pour une personne présente qui accepte de paraître
 * sur le site (23.10.2026).
 */
class PhotoEmployeController extends Controller
{
    /** Plus grand côté de la photo gardée, en pixels */
    private const int COTE = 800;

    public function televerser(TeleverserPhotoRequest $request, int $employe): SiteWebResource
    {
        $this->authorize('update', SiteWeb::class);

        $fiche = $this->employeDeLOrganisation($employe);
        $ancien = $fiche->photo_chemin;

        $fiche->update(['photo_chemin' => ImageReencodee::enregistrer($request->file('photo'), 'photos/'.$this->organisationId(), self::COTE)]);

        if ($ancien) {
            Storage::disk('prive')->delete($ancien);
        }

        return new SiteWebResource(SiteWeb::courant());
    }

    public function supprimer(int $employe): SiteWebResource
    {
        $this->authorize('update', SiteWeb::class);

        $fiche = $this->employeDeLOrganisation($employe);

        if ($fiche->photo_chemin) {
            Storage::disk('prive')->delete($fiche->photo_chemin);
            $fiche->update(['photo_chemin' => null]);
        }

        return new SiteWebResource(SiteWeb::courant());
    }

    public function afficher(string $fichier): StreamedResponse
    {
        $fiche = Employe::withoutGlobalScopes()->where('photo_fichier', pathinfo($fichier, PATHINFO_FILENAME))->firstOrFail();
        abort_unless(basename((string) $fiche->photo_chemin) === $fichier && Storage::disk('prive')->exists($fiche->photo_chemin), 404);
        // Partie, suspendue, ou sans son accord : sa photo ne se sert plus
        abort_unless($fiche->estPresentable() && $fiche->accord_site_le !== null, 404);

        return Storage::disk('prive')->response($fiche->photo_chemin, null, [
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
