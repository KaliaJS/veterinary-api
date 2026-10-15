<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProduitVeterinaireResource;
use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Catalogue des vaccins pour la clinique : les immunologiques autorisés en
 * Suisse, filtrés par espèce et recherche (marque, maladie, titulaire),
 * avec valences, schéma et codes-barres ; recherche par GTIN (scan d un
 * flacon). Données de plateforme : aucun contexte clinique.
 */
class VaccinCatalogueController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $espece = $request->string('espece')->toString();
        $recherche = trim($request->string('q')->toString());

        $produits = ProduitVeterinaire::query()
            ->whereIn('categorie', [ProduitVeterinaire::VACCIN, ProduitVeterinaire::IMMUNOLOGIQUE])
            ->when(! $request->boolean('retires'), fn (Builder $requete) => $requete->whereNull('retire_le'))
            ->when($espece !== '', fn (Builder $requete) => $requete->whereJsonContains('especes', $espece))
            ->when($recherche !== '', fn (Builder $requete) => $requete->where(fn (Builder $clause) => $clause
                ->where('nom_court', 'like', "%{$recherche}%")
                ->orWhere('titulaire', 'like', "%{$recherche}%")
                ->orWhereHas('valences', fn (Builder $valence) => $valence->where('nom', 'like', "%{$recherche}%")->orWhere('nom_court', 'like', "%{$recherche}%"))))
            ->with(['valences', 'schema', 'emballages'])
            ->orderBy('nom_court')
            ->get();

        return ProduitVeterinaireResource::collection($produits);
    }

    public function gtin(string $gtin): ProduitVeterinaireResource
    {
        $gtin = preg_replace('/\D/', '', $gtin);
        $emballage = ProduitEmballage::query()->where('gtin', $gtin)->with(['produit.valences', 'produit.schema', 'produit.emballages'])->firstOrFail();

        return (new ProduitVeterinaireResource($emballage->produit))->additional(['emballage' => [
            'id' => $emballage->id, 'numero' => $emballage->numero_emballage, 'gtin' => $emballage->gtin, 'nom_fr' => $emballage->nom_fr, 'taille' => $emballage->taille,
        ]]);
    }
}
