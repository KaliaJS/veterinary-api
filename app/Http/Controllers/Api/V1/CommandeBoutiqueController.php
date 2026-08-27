<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PieceNumerotee;
use App\Enums\StatutCommandeBoutique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Boutique\EnregistrerCommandeBoutiqueRequest;
use App\Http\Resources\V1\CommandeBoutiqueResource;
use App\Models\Client;
use App\Models\CommandeBoutique;
use App\Models\Produit;
use App\Services\GestionnaireStock;
use App\Services\Numeroteur;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Commandes de la boutique en ligne, clinique de retrait du jeton.
 * Cycle : à préparer → prête (client notifié) → retirée — la REMISE
 * sort le stock, avec mouvements tracés (le front, démo, ne le faisait
 * pas : décision produit, le stock quitte l'étagère à la remise).
 */
class CommandeBoutiqueController extends Controller
{
    public function __construct(
        private Numeroteur $numeroteur,
        private GestionnaireStock $stock,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CommandeBoutique::class);

        $commandes = CommandeBoutique::query()
            ->with('lignes.produit')
            ->when($request->enum('statut', StatutCommandeBoutique::class), fn ($requete, $statut) => $requete->where('statut', $statut))
            ->when($request->boolean('ouvertes'), fn ($requete) => $requete->where('statut', '!=', StatutCommandeBoutique::Retiree))
            ->latest('passee_le')
            ->latest('id')
            ->paginate($this->parPage($request));

        return CommandeBoutiqueResource::collection($commandes);
    }

    public function store(EnregistrerCommandeBoutiqueRequest $request): CommandeBoutiqueResource
    {
        $this->authorize('create', CommandeBoutique::class);

        $donnees = $request->validated();

        $commande = DB::transaction(function () use ($donnees): CommandeBoutique {
            $client = isset($donnees['client_id']) ? Client::findOrFail($donnees['client_id']) : null;

            $commande = CommandeBoutique::create([
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::CommandeBoutique),
                'client_id' => $client?->id,
                'client_nom' => $client?->nom_affiche ?? $donnees['client_nom'],
                'statut' => StatutCommandeBoutique::APreparer,
                'passee_le' => now(),
            ]);

            foreach ($donnees['lignes'] as $ligne) {
                $produit = Produit::findOrFail($ligne['produit_id']);

                // Prix effectif vitrine figé à la commande, promotion
                // comprise.
                $commande->lignes()->create([
                    'produit_id' => $produit->id,
                    'quantite' => $ligne['quantite'],
                    'prix_chf' => $produit->prixEffectif(),
                ]);
            }

            return $commande;
        });

        return new CommandeBoutiqueResource($commande->load('lignes.produit'));
    }

    public function preparer(Request $request, CommandeBoutique $commande): CommandeBoutiqueResource
    {
        $this->authorize('update', $commande);

        if ($commande->statut !== StatutCommandeBoutique::APreparer) {
            throw ValidationException::withMessages(['statut' => 'Seule une commande à préparer se marque prête.']);
        }

        $commande->update(['statut' => StatutCommandeBoutique::Prete]);

        return new CommandeBoutiqueResource($commande->load('lignes.produit'));
    }

    public function retirer(Request $request, CommandeBoutique $commande): CommandeBoutiqueResource
    {
        $this->authorize('update', $commande);

        if ($commande->statut !== StatutCommandeBoutique::Prete) {
            throw ValidationException::withMessages(['statut' => 'Seule une commande prête se remet au client.']);
        }

        DB::transaction(function () use ($request, $commande): void {
            $commande->update([
                'statut' => StatutCommandeBoutique::Retiree,
                'retiree_le' => now(),
            ]);

            foreach ($commande->lignes()->with('produit')->get() as $ligne) {
                $this->stock->sortir(
                    $ligne->produit,
                    $ligne->quantite,
                    'Commande en ligne '.$commande->numero,
                    $request->user(),
                    $commande,
                );
            }
        });

        return new CommandeBoutiqueResource($commande->load('lignes.produit'));
    }
}
