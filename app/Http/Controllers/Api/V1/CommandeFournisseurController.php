<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PieceNumerotee;
use App\Enums\StatutCommandeFournisseur;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Stocks\EnregistrerCommandeFournisseurRequest;
use App\Http\Resources\V1\CommandeFournisseurResource;
use App\Models\CommandeFournisseur;
use App\Models\Lot;
use App\Models\Produit;
use App\Services\GestionnaireStock;
use App\Services\Numeroteur;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bons de commande fournisseurs de la clinique du jeton (clinique de
 * livraison). `generer` regroupe les articles sous seuil par
 * fournisseur en brouillons ; la réception incrémente le stock, trace
 * les mouvements et crée un lot par article périssable. Suppression
 * tolérée tant que la commande n'est pas réceptionnée.
 */
class CommandeFournisseurController extends Controller
{
    public function __construct(
        private Numeroteur $numeroteur,
        private GestionnaireStock $stock,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CommandeFournisseur::class);

        $commandes = CommandeFournisseur::query()
            ->with('lignes')
            ->when($request->enum('statut', StatutCommandeFournisseur::class), fn ($requete, $statut) => $requete->where('statut', $statut))
            ->latest('id')
            ->paginate($this->parPage($request));

        return CommandeFournisseurResource::collection($commandes);
    }

    public function store(EnregistrerCommandeFournisseurRequest $request): CommandeFournisseurResource
    {
        $this->authorize('create', CommandeFournisseur::class);

        $donnees = $request->validated();

        $commande = DB::transaction(function () use ($donnees): CommandeFournisseur {
            $commande = CommandeFournisseur::create([
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::CommandeFournisseur),
                'fournisseur' => $donnees['fournisseur'],
                'statut' => StatutCommandeFournisseur::Brouillon,
            ]);

            foreach ($donnees['lignes'] as $ligne) {
                $produit = Produit::findOrFail($ligne['produit_id']);

                $commande->lignes()->create([
                    'produit_id' => $produit->id,
                    'quantite' => $ligne['quantite'],
                    'cout' => $produit->cout,
                ]);
            }

            return $commande;
        });

        return new CommandeFournisseurResource($commande->load('lignes'));
    }

    /**
     * Brouillons automatiques : les articles dont le stock total de
     * l'organisation est sous le seuil, sans commande ouverte (brouillon
     * ou envoyée) de la clinique les couvrant, regroupés par
     * fournisseur. Quantité proposée : remonter à deux fois le seuil.
     */
    public function generer(Request $request): AnonymousResourceCollection
    {
        $this->authorize('create', CommandeFournisseur::class);

        $couverts = CommandeFournisseur::query()
            ->where('statut', '!=', StatutCommandeFournisseur::Receptionnee)
            ->with('lignes')
            ->get()
            ->flatMap(fn (CommandeFournisseur $commande) => $commande->lignes->pluck('produit_id'))
            ->all();

        $sousSeuil = Produit::query()
            ->with(['stocks' => fn ($requete) => $requete->withoutGlobalScope('clinique')])
            ->whereNotIn('id', $couverts)
            ->get()
            ->filter(fn (Produit $produit): bool => $produit->seuil_alerte > 0 && $produit->estSousLeSeuil());

        if ($sousSeuil->isEmpty()) {
            throw ValidationException::withMessages([
                'commandes' => 'Aucun article sous seuil sans commande en cours.',
            ]);
        }

        $commandes = DB::transaction(fn () => $sousSeuil
            ->groupBy(fn (Produit $produit): string => $produit->fournisseur ?? 'Interne')
            ->map(function ($produits, string $fournisseur): CommandeFournisseur {
                $commande = CommandeFournisseur::create([
                    'numero' => $this->numeroteur->attribuer(PieceNumerotee::CommandeFournisseur),
                    'fournisseur' => $fournisseur,
                    'statut' => StatutCommandeFournisseur::Brouillon,
                ]);

                foreach ($produits as $produit) {
                    $commande->lignes()->create([
                        'produit_id' => $produit->id,
                        'quantite' => max($produit->seuil_alerte * 2 - $produit->stockTotal(), $produit->seuil_alerte),
                        'cout' => $produit->cout,
                    ]);
                }

                return $commande;
            })
            ->values());

        return CommandeFournisseurResource::collection($commandes->each->load('lignes'));
    }

    public function envoyer(Request $request, CommandeFournisseur $commande): CommandeFournisseurResource
    {
        $this->authorize('update', $commande);

        if ($commande->statut !== StatutCommandeFournisseur::Brouillon) {
            throw ValidationException::withMessages(['statut' => 'Seul un brouillon s’envoie.']);
        }

        if ($commande->lignes()->doesntExist()) {
            throw ValidationException::withMessages(['lignes' => 'Ajoutez au moins une ligne avant d’envoyer.']);
        }

        $commande->update([
            'statut' => StatutCommandeFournisseur::Envoyee,
            'envoyee_le' => now(),
        ]);

        return new CommandeFournisseurResource($commande->load('lignes'));
    }

    public function receptionner(Request $request, CommandeFournisseur $commande): CommandeFournisseurResource
    {
        $this->authorize('update', $commande);

        if ($commande->statut !== StatutCommandeFournisseur::Envoyee) {
            throw ValidationException::withMessages(['statut' => 'Seule une commande envoyée se réceptionne.']);
        }

        DB::transaction(function () use ($request, $commande): void {
            $commande->update([
                'statut' => StatutCommandeFournisseur::Receptionnee,
                'receptionnee_le' => now(),
            ]);

            foreach ($commande->lignes()->with('produit')->get() as $ligne) {
                $this->stock->entrer(
                    $ligne->produit,
                    $ligne->quantite,
                    'Réception '.$commande->numero,
                    $request->user(),
                    $commande,
                );

                if ($ligne->produit->peremption_le !== null) {
                    Lot::create([
                        'produit_id' => $ligne->produit_id,
                        'clinique_id' => $commande->clinique_id,
                        'numero_lot' => 'LOT '.substr($commande->numero, -3).'-'.substr($ligne->produit->reference, -2),
                        'peremption_le' => $ligne->produit->peremption_le,
                        'quantite' => $ligne->quantite,
                    ]);
                }
            }
        });

        return new CommandeFournisseurResource($commande->load('lignes'));
    }

    public function destroy(CommandeFournisseur $commande): Response
    {
        $this->authorize('delete', $commande);

        if ($commande->statut === StatutCommandeFournisseur::Receptionnee) {
            throw ValidationException::withMessages([
                'statut' => 'Une commande réceptionnée a fait bouger le stock — elle ne se supprime plus.',
            ]);
        }

        $commande->delete();

        return response()->noContent();
    }
}
