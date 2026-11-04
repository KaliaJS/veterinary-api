<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Site\TeleverserPhotoRequest;
use App\Http\Resources\V1\ProduitResource;
use App\Models\Produit;
use App\Services\Photos\ImageReencodee;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La photo de la vitrine d'un article vendu en boutique (nouvel article B,
 * 03.11.2026), sur le modèle de la photo des animaux : réécrite en WebP sur
 * le disque privé, au nom imprévisible, servie par une route publique (la
 * vitrine en ligne la montre à tous). Sans photo, le dessin de la famille.
 */
class ProductPhotoController extends Controller
{
    public function upload(TeleverserPhotoRequest $request, Produit $produit): ProduitResource
    {
        $this->authorize('update', $produit);
        // La vitrine seulement, jamais un stupéfiant (04.11.2026)
        if ($produit->est_stupefiant) {
            throw ValidationException::withMessages(['photo' => 'Un stupéfiant ne s’affiche pas en boutique : pas de photo de vitrine.']);
        }
        if (! $produit->est_boutique) {
            throw ValidationException::withMessages(['photo' => 'La photo est celle de la vitrine : mettez d’abord l’article en boutique.']);
        }

        $old = $produit->photo_chemin;
        $produit->update(['photo_chemin' => ImageReencodee::enregistrer($request->file('photo'), 'photos-produits/'.$produit->organisation_id, 1200)]);
        if ($old) {
            Storage::disk('prive')->delete($old);
        }

        return $this->resource($produit);
    }

    public function remove(Produit $produit): ProduitResource
    {
        $this->authorize('update', $produit);

        if ($produit->photo_chemin) {
            Storage::disk('prive')->delete($produit->photo_chemin);
            $produit->update(['photo_chemin' => null]);
        }

        return $this->resource($produit);
    }

    public function show(string $fichier): StreamedResponse
    {
        $produit = Produit::withoutGlobalScopes()->where('photo_fichier', pathinfo($fichier, PATHINFO_FILENAME))->firstOrFail();
        abort_unless(basename((string) $produit->photo_chemin) === $fichier && Storage::disk('prive')->exists($produit->photo_chemin), 404);

        return Storage::disk('prive')->response($produit->photo_chemin, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function resource(Produit $produit): ProduitResource
    {
        return new ProduitResource($produit->load(['stocks' => fn ($requete) => $requete->withoutGlobalScope('clinique'), 'vetpoint', 'sources.supplier']));
    }
}
