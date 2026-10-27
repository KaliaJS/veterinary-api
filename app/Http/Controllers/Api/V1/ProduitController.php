<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CategorieProduit;
use App\Enums\MotifAjustementStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Stocks\EnregistrerProduitRequest;
use App\Http\Resources\V1\ProduitResource;
use App\Models\Produit;
use App\Services\GestionnaireStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Catalogue d'organisation, quantités par clinique : la lecture expose
 * la ventilation de toutes les cliniques (parité front), les écritures
 * visent la clinique du jeton ou une clinique sœur explicite. Le stock
 * initial d'un article créé entre au journal comme une réception.
 */
class ProduitController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Produit::class);

        $produits = Produit::query()
            ->with(['stocks' => fn ($requete) => $requete->withoutGlobalScope('clinique'), 'vetpoint'])
            ->when($request->enum('categorie', CategorieProduit::class), fn (Builder $requete, CategorieProduit $categorie) => $requete->where('categorie', $categorie))
            ->when($request->filled('q'), function (Builder $requete) use ($request): void {
                $terme = '%'.$request->string('q')->trim()->lower().'%';
                $requete->where(fn (Builder $clause) => $clause
                    ->whereRaw('lower(nom) like ?', [$terme])
                    ->orWhereRaw('lower(reference) like ?', [$terme])
                    ->orWhereRaw('lower(coalesce(fournisseur, \'\')) like ?', [$terme])
                    ->orWhereRaw('lower(coalesce(ean, \'\')) like ?', [$terme]));
            })
            ->orderBy('nom')
            ->paginate($this->parPage($request, 100));

        return ProduitResource::collection($produits);
    }

    public function store(EnregistrerProduitRequest $request, GestionnaireStock $stock): ProduitResource
    {
        $this->authorize('create', Produit::class);

        $donnees = $request->validated();

        $produit = Produit::create([
            'nom' => $donnees['nom'],
            'categorie' => $donnees['categorie'],
            'reference' => $this->prochaineReference(),
            'prix' => $donnees['prix'],
            'cout' => $donnees['cout'] ?? 0,
            'unite' => $donnees['unite'] ?? 'pièce',
            'seuil_alerte' => $donnees['seuil_alerte'] ?? 0,
            'est_boutique' => $donnees['est_boutique'] ?? false,
            'est_sur_ordonnance' => $donnees['est_sur_ordonnance'] ?? false,
            'peremption_le' => $request->peremptionValidee(),
            'fournisseur' => $donnees['fournisseur'] ?? null,
            'ean' => $donnees['ean'] ?? null,
        ]);

        $quantiteInitiale = (int) ($donnees['quantite_initiale'] ?? 0);

        if ($quantiteInitiale > 0) {
            $stock->appliquer($produit, $quantiteInitiale, 'Stock initial', $request->user(), MotifAjustementStock::Reception, cliniqueId: $request->cliniqueId());
        }

        return new ProduitResource(
            $produit->load(['stocks' => fn ($requete) => $requete->withoutGlobalScope('clinique'), 'vetpoint']),
        );
    }

    public function show(Produit $produit): ProduitResource
    {
        $this->authorize('view', $produit);

        return new ProduitResource(
            $produit->load(['stocks' => fn ($requete) => $requete->withoutGlobalScope('clinique'), 'vetpoint']),
        );
    }

    /** Référence interne « ART-0025 » — séquentielle dans l'organisation. */
    private function prochaineReference(): string
    {
        $numero = Produit::count() + 1;

        while (Produit::where('reference', sprintf('ART-%04d', $numero))->exists()) {
            $numero++;
        }

        return sprintf('ART-%04d', $numero);
    }
}
