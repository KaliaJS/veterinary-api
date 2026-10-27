<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\PieceNumerotee;
use App\Enums\StatutCommandeFournisseur;
use App\Http\Controllers\Api\V1\Vetpoint\CommandeVetpointController;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Stocks\EnregistrerCommandeFournisseurRequest;
use App\Http\Resources\V1\CommandeFournisseurResource;
use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\Lot;
use App\Models\Produit;
use App\Services\GestionnaireStock;
use App\Services\Numeroteur;
use App\Services\RegistreStupefiants;
use App\Services\Vetpoint\ClientVetpoint;
use App\Services\Vetpoint\VetpointCgv;
use App\Services\Vetpoint\VetpointCleRefusee;
use App\Services\Vetpoint\VetpointIndisponible;
use App\Services\Vetpoint\VetpointLimite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Bons de commande fournisseurs de l'ORGANISATION : la clinique de
 * livraison est celle du jeton par défaut, ou une clinique sœur (la
 * ventilation des stocks est transversale, l'écran règle toutes les
 * cliniques) ; lecture et gestes couvrent toutes les cliniques de
 * l'organisation, résolution sans binding implicite. `generer` regroupe
 * les articles sous seuil par fournisseur en brouillons ; la réception
 * incrémente le stock de la clinique de livraison, trace les mouvements
 * et crée un lot par article périssable. Suppression tolérée tant que
 * la commande n'est pas réceptionnée.
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
            ->dansLOrganisation()
            ->with('lignes')
            ->when($request->filled('clinique_id'), fn ($requete) => $requete->where('clinique_id', $request->integer('clinique_id')))
            ->when($request->enum('statut', StatutCommandeFournisseur::class), fn ($requete, $statut) => $requete->where('statut', $statut))
            ->latest('id')
            ->paginate($this->parPage($request));

        return CommandeFournisseurResource::collection($commandes);
    }

    public function store(EnregistrerCommandeFournisseurRequest $request): CommandeFournisseurResource
    {
        $this->authorize('create', CommandeFournisseur::class);

        $donnees = $request->validated();
        $cliniqueId = $request->cliniqueId() ?? app(ContexteClinique::class)->cliniqueId();

        $commande = DB::transaction(function () use ($donnees, $cliniqueId): CommandeFournisseur {
            $commande = $this->creerBrouillon($cliniqueId, $donnees['fournisseur']);

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
     * Livraison à la clinique du jeton, ou à la clinique sœur demandée.
     */
    public function generer(Request $request): AnonymousResourceCollection
    {
        $this->authorize('create', CommandeFournisseur::class);

        $request->validate(['clinique_id' => ['sometimes', 'nullable', 'integer']]);
        $cliniqueId = $this->cliniqueDeLivraison($request);

        $couverts = CommandeFournisseur::query()
            ->dansLOrganisation()
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
            ->map(function ($produits, string $fournisseur) use ($cliniqueId): CommandeFournisseur {
                $commande = $this->creerBrouillon($cliniqueId, $fournisseur);

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

    public function envoyer(Request $request, int $commande): CommandeFournisseurResource
    {
        $commande = $this->commandeDeLOrganisation($commande);

        $this->authorize('update', $commande);

        if ($commande->statut !== StatutCommandeFournisseur::Brouillon) {
            throw ValidationException::withMessages(['statut' => 'Seul un brouillon s’envoie.']);
        }

        if ($commande->lignes()->doesntExist()) {
            throw ValidationException::withMessages(['lignes' => 'Ajoutez au moins une ligne avant d’envoyer.']);
        }

        // Sous verrou, comme l'envoi par Vetpoint : un envoi par Vetpoint en
        // suspens (réponse perdue) empêche l'envoi classique
        $commande = DB::transaction(function () use ($commande): CommandeFournisseur {
            $verrouillee = CommandeFournisseur::query()->dansLOrganisation()->whereKey($commande->id)->lockForUpdate()->firstOrFail();
            if ($verrouillee->statut !== StatutCommandeFournisseur::Brouillon) {
                throw ValidationException::withMessages(['statut' => 'Seul un brouillon s’envoie.']);
            }
            CommandeVetpointController::exigerHorsEnvoiEnSuspens($verrouillee);
            $verrouillee->update([
                'statut' => StatutCommandeFournisseur::Envoyee,
                'envoyee_le' => now(),
            ]);

            return $verrouillee;
        });

        return new CommandeFournisseurResource($commande->load('lignes'));
    }

    /** Le stock et les lots entrent dans la clinique de LIVRAISON. */
    public function receptionner(Request $request, int $commande): CommandeFournisseurResource
    {
        $commande = $this->commandeDeLOrganisation($commande);

        $this->authorize('update', $commande);

        // Passée par Vetpoint : prix réels et péremptions lus chez lui (28.10.2026).
        // Vetpoint injoignable : le stock entre quand même, avec les prix de la commande
        $vetpoint = null;
        $parLigne = collect();
        if ($commande->vetpoint_commande_id !== null) {
            try {
                $detail = CommandeVetpointController::clientDe($commande->clinique_id)->commande($commande->vetpoint_commande_id);
                $parLigne = collect(CommandeVetpointController::lignesDetaillees($commande, $detail))->filter(fn (array $ligne) => $ligne['ligne_id'] !== null)->keyBy('ligne_id');
                $vetpoint = ['lu' => true, 'message' => null];
                // Un prix nul ou négatif n'est pas un prix réel : pas repris
                $repris = $parLigne->filter(fn (array $ligne): bool => ($ligne['prix_unitaire'] ?? 0) > 0)->count();
                $total = $commande->lignes()->count();
                $vetpoint['prix_repris'] = $repris;
                $vetpoint['message'] = match (true) {
                    $repris === 0 => 'Vetpoint ne donne pas encore les prix réels : le stock est entré avec les prix de la commande.',
                    $repris < $total => 'Prix réels de Vetpoint repris pour '.$repris.' '.($repris > 1 ? 'lignes' : 'ligne').' sur '.$total.' ; les autres gardent le prix de la commande.',
                    default => null,
                };
            } catch (VetpointCleRefusee|VetpointCgv|VetpointLimite|VetpointIndisponible) {
                $vetpoint = ['lu' => false, 'message' => 'Vetpoint ne répond pas : le stock est entré avec les prix de la commande, sans les prix réels ni les péremptions de Vetpoint.'];
            } catch (HttpException $e) {
                // Clé absente ou illisible : même chose, avec la raison
                $vetpoint = ['lu' => false, 'message' => $e->getMessage().' Le stock est entré avec les prix de la commande.'];
            }
        }

        // Sous verrou : une commande n'entre jamais deux fois en stock
        $commande = DB::transaction(function () use ($request, $commande, $parLigne): CommandeFournisseur {
            $commande = CommandeFournisseur::query()->dansLOrganisation()->whereKey($commande->id)->lockForUpdate()->firstOrFail();

            if ($commande->statut !== StatutCommandeFournisseur::Envoyee) {
                throw ValidationException::withMessages(['statut' => 'Seule une commande envoyée se réceptionne.']);
            }

            $commande->update([
                'statut' => StatutCommandeFournisseur::Receptionnee,
                'receptionnee_le' => now(),
            ]);

            foreach ($commande->lignes()->with('produit')->get() as $ligne) {
                // Le prix réel de Vetpoint : sur la ligne et comme coût du produit
                $reel = $parLigne->get($ligne->id);
                if ($reel !== null && $reel['prix_unitaire'] !== null && $reel['prix_unitaire'] > 0) {
                    $ligne->forceFill(['cout' => $reel['prix_unitaire']])->save();
                    $ligne->produit->forceFill(['cout' => $reel['prix_unitaire']])->save();
                }
                $peremption = $reel !== null ? ClientVetpoint::peremption($reel['peremption']) : null;

                // Un stupéfiant entre par le registre, signé par qui réceptionne,
                // avec le droit des stupéfiants ; le stock le suit (24.10.2026)
                if ($ligne->produit->est_stupefiant) {
                    Gate::authorize(DomainePermission::Stupefiants->value);
                    app(RegistreStupefiants::class)->inscrire($ligne->produit, (float) $ligne->quantite, 'Réception '.$commande->numero, $commande->clinique_id, $request->user());
                } else {
                    $this->stock->entrer(
                        $ligne->produit,
                        $ligne->quantite,
                        'Réception '.$commande->numero,
                        $request->user(),
                        $commande,
                        cliniqueId: $commande->clinique_id,
                    );
                }

                if ($peremption !== null || $ligne->produit->peremption_le !== null) {
                    // Lot de la clinique de livraison, qui peut être
                    // une clinique sœur : hors événements du trait. La
                    // péremption de Vetpoint, sinon celle du produit
                    Lot::withoutEvents(fn (): Lot => Lot::create([
                        'produit_id' => $ligne->produit_id,
                        'clinique_id' => $commande->clinique_id,
                        'numero_lot' => 'LOT '.substr($commande->numero, -3).'-'.substr($ligne->produit->reference, -2),
                        'peremption_le' => $peremption ?? $ligne->produit->peremption_le,
                        'quantite' => $ligne->quantite,
                    ]));
                }
            }

            return $commande;
        });

        $commande->receptionVetpoint = $vetpoint;

        return new CommandeFournisseurResource($commande->load('lignes'));
    }

    public function destroy(int $commande): Response
    {
        $commande = $this->commandeDeLOrganisation($commande);

        $this->authorize('delete', $commande);

        if ($commande->statut === StatutCommandeFournisseur::Receptionnee) {
            throw ValidationException::withMessages([
                'statut' => 'Une commande réceptionnée a fait bouger le stock, elle ne se supprime plus.',
            ]);
        }
        CommandeVetpointController::exigerHorsEnvoiEnSuspens($commande);

        $commande->delete();

        return response()->noContent();
    }

    /**
     * Résolution SANS binding implicite (qui bornerait à la clinique du
     * jeton) : la commande de n'importe quelle clinique de l'organisation.
     */
    public static function commandeDeLOrganisation(int $id): CommandeFournisseur
    {
        return CommandeFournisseur::query()->dansLOrganisation()->findOrFail($id);
    }

    /**
     * Brouillon numéroté pour la clinique de livraison — une clinique
     * sœur est posée hors événements du trait (dérogation documentée à
     * l'invariant d'écriture locale, la cible est validée dans
     * l'organisation).
     */
    private function creerBrouillon(int $cliniqueId, string $fournisseur): CommandeFournisseur
    {
        return CommandeFournisseur::withoutEvents(fn (): CommandeFournisseur => CommandeFournisseur::create([
            'clinique_id' => $cliniqueId,
            'numero' => $this->numeroteur->attribuer(PieceNumerotee::CommandeFournisseur),
            'fournisseur' => $fournisseur,
            'statut' => StatutCommandeFournisseur::Brouillon,
        ]));
    }

    /** @throws ValidationException si la clinique demandée n'est pas de l'organisation */
    private function cliniqueDeLivraison(Request $request): int
    {
        $contexte = app(ContexteClinique::class);

        if (! $request->filled('clinique_id')) {
            return $contexte->cliniqueId();
        }

        $cliniqueId = $request->integer('clinique_id');

        $estDeLOrganisation = Clinique::query()
            ->whereKey($cliniqueId)
            ->whereRelation('societe', 'organisation_id', $contexte->organisationId())
            ->exists();

        return $estDeLOrganisation ? $cliniqueId : throw ValidationException::withMessages([
            'clinique_id' => 'Cette clinique n’est pas de l’organisation.',
        ]);
    }
}
