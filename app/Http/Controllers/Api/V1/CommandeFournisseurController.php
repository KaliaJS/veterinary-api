<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\PieceNumerotee;
use App\Enums\StatutCommandeFournisseur;
use App\Http\Controllers\Api\V1\Vetpoint\CommandeVetpointController;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Stocks\EnregistrerCommandeFournisseurRequest;
use App\Http\Requests\V1\Stocks\GenerateSupplierOrdersRequest;
use App\Http\Resources\V1\CommandeFournisseurResource;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Bons de commande fournisseurs de la clinique du jeton : on commande
 * depuis la clinique qui reçoit (décision de Jim, 28.10.2026), celles
 * d'une clinique sœur ne se voient ni ne se touchent d'ici. `generer`
 * regroupe les articles sous seuil par fournisseur en brouillons ; la
 * réception incrémente le stock de la clinique, trace les mouvements et
 * crée un lot par article périssable. Suppression tolérée tant que la
 * commande n'est pas réceptionnée.
 */
class CommandeFournisseurController extends Controller
{
    public function __construct(
        private Numeroteur $numeroteur,
        private GestionnaireStock $stock,
    ) {}

    /**
     * Les commandes de la clinique ouverte (onglet « courrier », 03.11.2026) :
     * `statut` en liste (`draft,sent`, ou `brouillon,envoyee`), `q` cherche
     * dans le numéro, le fournisseur, le nom ou la référence d'un article.
     * Les ouvertes d'abord, de la plus récente à la plus ancienne ; puis les
     * reçues, de la dernière réception à la première.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CommandeFournisseur::class);

        $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $statuts = self::statutsDemandes($request->input('statut'));
        $terme = trim((string) $request->input('q', ''));

        $commandes = CommandeFournisseur::query()
            ->with('lignes')
            ->when($statuts !== [], fn ($requete) => $requete->whereIn('statut', $statuts))
            ->when($terme !== '', function ($requete) use ($terme): void {
                // « % », « _ » et le caractère d'échappement se cherchent tels quels (vérification du 03.11.2026)
                $motif = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $terme).'%';
                $requete->where(fn ($ou) => $ou
                    ->whereRaw("numero like ? escape '!'", [$motif])
                    ->orWhereRaw("fournisseur like ? escape '!'", [$motif])
                    ->orWhereHas('lignes.produit', fn ($produit) => $produit->where(fn ($p) => $p
                        ->whereRaw("nom like ? escape '!'", [$motif])
                        ->orWhereRaw("reference like ? escape '!'", [$motif]))));
            })
            ->orderByRaw('case when statut = ? then 1 else 0 end', [StatutCommandeFournisseur::Receptionnee->value])
            ->orderByDesc('receptionnee_le')
            ->orderByDesc('id')
            ->paginate($this->parPage($request));

        return CommandeFournisseurResource::collection($commandes);
    }

    /**
     * Les titres de mois des reçues (03.11.2026) : pour chaque mois de
     * réception, à l'heure de la clinique, le nombre de commandes et ce
     * qu'elles ont coûté. Du plus récent au plus ancien.
     */
    public function months(): JsonResponse
    {
        $this->authorize('viewAny', CommandeFournisseur::class);

        $fuseau = app(ContexteClinique::class)->clinique()?->fuseau_horaire ?: 'Europe/Zurich';
        $totaux = CommandeFournisseurLigne::query()
            ->selectRaw('commande_fournisseur_id, sum(quantite * round(cout * 100)) as centimes')
            ->groupBy('commande_fournisseur_id');

        $mois = CommandeFournisseur::query()
            ->where('statut', StatutCommandeFournisseur::Receptionnee)
            ->whereNotNull('receptionnee_le')
            ->leftJoinSub($totaux, 'totaux', 'totaux.commande_fournisseur_id', '=', 'commandes_fournisseur.id')
            ->get(['commandes_fournisseur.id', 'commandes_fournisseur.receptionnee_le', 'totaux.centimes'])
            ->groupBy(fn (CommandeFournisseur $commande): string => $commande->receptionnee_le->copy()->setTimezone($fuseau)->format('Y-m'))
            ->sortKeysDesc()
            ->map(fn ($groupe, string $mois): array => [
                'mois' => $mois,
                'nombre' => $groupe->count(),
                'total_chf' => (int) round($groupe->sum(fn (CommandeFournisseur $commande): float => (float) $commande->getAttribute('centimes'))) / 100,
            ])
            ->values();

        return response()->json(['data' => $mois]);
    }

    /**
     * Ce que « Préparer les bons » créerait (03.11.2026), avec la règle de
     * `generer` : les articles sous le seuil dans la clinique ouverte, sans
     * commande en cours, rangés par fournisseur. Rien n'est créé.
     */
    public function lowStock(): JsonResponse
    {
        $this->authorize('viewAny', CommandeFournisseur::class);

        $cliniqueId = (int) app(ContexteClinique::class)->cliniqueId();

        $groupes = $this->articlesSousSeuil($cliniqueId)
            ->groupBy(fn (Produit $produit): string => $produit->fournisseur ?? 'Interne')
            ->sortKeys()
            ->map(fn ($produits, string $fournisseur): array => [
                'fournisseur' => $fournisseur,
                'articles' => $produits->sortBy('nom')->map(fn (Produit $produit): array => [
                    'produit_id' => $produit->id,
                    'nom' => $produit->nom,
                    'reference' => $produit->reference,
                    'en_stock' => (int) $produit->stockDans($cliniqueId),
                    'seuil' => (int) $produit->seuil_alerte,
                    'quantite_proposee' => self::quantiteProposee($produit, $cliniqueId),
                    'cout' => (float) $produit->cout,
                ])->values()->all(),
            ])
            ->values();

        return response()->json(['data' => $groupes]);
    }

    public function store(EnregistrerCommandeFournisseurRequest $request): CommandeFournisseurResource
    {
        $this->authorize('create', CommandeFournisseur::class);

        $donnees = $request->validated();

        $commande = DB::transaction(function () use ($donnees): CommandeFournisseur {
            // Les verrous dans le même ordre que « Préparer » : la clinique, puis la numérotation
            self::verrouillerLaClinique();
            $commande = $this->creerBrouillon($donnees['fournisseur']);

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
     * Brouillons automatiques, par clinique (décision de Jim, 28.10.2026) :
     * les articles que la clinique du jeton tient et dont son stock est sous
     * le seuil, sans commande ouverte (brouillon ou envoyée) de cette
     * clinique les couvrant, regroupés par fournisseur. Quantité proposée :
     * remonter son stock à deux fois le seuil. Avec `lignes` (03.11.2026) :
     * seulement les articles cochés, aux quantités corrigées.
     */
    public function generer(GenerateSupplierOrdersRequest $request): AnonymousResourceCollection
    {
        $this->authorize('create', CommandeFournisseur::class);

        $cliniqueId = (int) app(ContexteClinique::class)->cliniqueId();

        // Sous verrou de la clinique (vérification du 03.11.2026) : deux « Préparer »
        // au même instant lisent l'un après l'autre ce qui est déjà en commande,
        // et ne doublent jamais les brouillons
        $commandes = DB::transaction(function () use ($request, $cliniqueId) {
            self::verrouillerLaClinique();

            if ($request->has('lignes')) {
                $lignes = collect($request->validated('lignes'));
                $produits = Produit::query()->whereIn('id', $lignes->pluck('produit_id'))->get()->keyBy('id');
                // Un article déjà en commande est refusé
                $enCommande = $this->produitsEnCommande();
                foreach ($lignes as $i => $ligne) {
                    if (in_array((int) $ligne['produit_id'], $enCommande, true)) {
                        throw ValidationException::withMessages([
                            "lignes.{$i}.produit_id" => $produits->get($ligne['produit_id'])?->nom.' est déjà sur une commande en cours.',
                        ]);
                    }
                }
                $quantites = $lignes->mapWithKeys(fn (array $ligne): array => [(int) $ligne['produit_id'] => (int) $ligne['quantite']]);
                $choisis = $lignes->map(fn (array $ligne): Produit => $produits->get($ligne['produit_id']));
            } else {
                $choisis = $this->articlesSousSeuil($cliniqueId);
                $quantites = $choisis->mapWithKeys(fn (Produit $produit): array => [$produit->id => self::quantiteProposee($produit, $cliniqueId)]);

                if ($choisis->isEmpty()) {
                    throw ValidationException::withMessages([
                        'commandes' => 'Aucun article sous seuil sans commande en cours.',
                    ]);
                }
            }

            return $choisis
                ->groupBy(fn (Produit $produit): string => $produit->fournisseur ?? 'Interne')
                ->sortKeys()
                ->map(function ($produits, string $fournisseur) use ($quantites): CommandeFournisseur {
                    $commande = $this->creerBrouillon($fournisseur);

                    foreach ($produits as $produit) {
                        $commande->lignes()->create([
                            'produit_id' => $produit->id,
                            'quantite' => $quantites[$produit->id],
                            'cout' => $produit->cout,
                        ]);
                    }

                    return $commande;
                })
                ->values();
        });

        return CommandeFournisseurResource::collection($commandes->each->load('lignes'));
    }

    public function envoyer(Request $request, int $commande): CommandeFournisseurResource
    {
        $commande = self::commandeDeLaClinique($commande);

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
            $verrouillee = CommandeFournisseur::query()->whereKey($commande->id)->lockForUpdate()->firstOrFail();
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

    /** Le stock et les lots entrent dans la clinique de la commande, celle du jeton. */
    public function receptionner(Request $request, int $commande): CommandeFournisseurResource
    {
        $commande = self::commandeDeLaClinique($commande);

        $this->authorize('update', $commande);

        if ($commande->statut !== StatutCommandeFournisseur::Envoyee) {
            throw ValidationException::withMessages(['statut' => 'Seule une commande envoyée se réceptionne.']);
        }

        // Un stupéfiant reçu : le bulletin de livraison et sa date, inscrits au registre (art. 57, 62 et 63 OCStup)
        $aDesStupefiants = $commande->lignes()->whereHas('produit', fn ($produit) => $produit->where('est_stupefiant', true))->exists();
        // Sans le droit des stupéfiants, un refus avant toute question de bulletin
        if ($aDesStupefiants) {
            Gate::authorize(DomainePermission::Stupefiants->value);
        }
        $bulletin = $request->validate([
            'bulletin' => [Rule::requiredIf($aDesStupefiants), 'nullable', 'string', 'max:60'],
            'bulletin_le' => ['bail', Rule::requiredIf($aDesStupefiants), 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.aujourdhui($commande->clinique)->toDateString()],
        ], [
            'bulletin.required' => 'Indiquez le numéro du bulletin de livraison : la commande contient un stupéfiant.',
            'bulletin_le.required' => 'Indiquez la date du bulletin de livraison : la commande contient un stupéfiant.',
            'bulletin_le.before_or_equal' => 'La date du bulletin ne peut pas être dans le futur.',
            'bulletin_le.date_format' => 'Indiquez la date du bulletin.',
        ]);
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
                    // Les prix, mais pas encore les péremptions
                    $parLigne->every(fn (array $ligne): bool => ClientVetpoint::peremption($ligne['peremption']) === null) => 'Prix réels de Vetpoint repris ; Vetpoint ne donne pas encore les péremptions : les lots prennent celle du catalogue.',
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
        $commande = DB::transaction(function () use ($request, $commande, $parLigne, $bulletin): CommandeFournisseur {
            $commande = CommandeFournisseur::query()->whereKey($commande->id)->lockForUpdate()->firstOrFail();

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
                    app(RegistreStupefiants::class)->inscrire($ligne->produit, (float) $ligne->quantite, 'Réception '.$commande->numero, $commande->clinique_id, $request->user(), [
                        // Le fournisseur et la pièce : ce que demande l'art. 57 OCStup (01.11.2026)
                        'fournisseur' => $commande->fournisseur ?: 'Fournisseur de la commande '.$commande->numero,
                        'bulletin' => $bulletin['bulletin'],
                        'bulletin_le' => $bulletin['bulletin_le'],
                    ]);
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
                    // Lot de la clinique : la péremption de Vetpoint, sinon celle du produit
                    Lot::create([
                        'produit_id' => $ligne->produit_id,
                        'clinique_id' => $commande->clinique_id,
                        'numero_lot' => 'LOT '.substr($commande->numero, -3).'-'.substr($ligne->produit->reference, -2),
                        'peremption_le' => $peremption ?? $ligne->produit->peremption_le,
                        'quantite' => $ligne->quantite,
                    ]);
                }
            }

            return $commande;
        });

        $commande->receptionVetpoint = $vetpoint;

        return new CommandeFournisseurResource($commande->load('lignes'));
    }

    public function destroy(int $commande): Response
    {
        $commande = self::commandeDeLaClinique($commande);

        $this->authorize('delete', $commande);

        // Sous verrou, comme l'envoi par Vetpoint : jamais supprimée pendant qu'elle part
        DB::transaction(function () use ($commande): void {
            $verrouillee = CommandeFournisseur::query()->whereKey($commande->id)->lockForUpdate()->firstOrFail();
            if ($verrouillee->statut === StatutCommandeFournisseur::Receptionnee) {
                throw ValidationException::withMessages([
                    'statut' => 'Une commande réceptionnée a fait bouger le stock, elle ne se supprime plus.',
                ]);
            }
            CommandeVetpointController::exigerHorsEnvoiEnSuspens($verrouillee);
            $verrouillee->delete();
        });

        return response()->noContent();
    }

    /** La commande de la clinique du jeton ; celle d'une autre clinique n'existe pas d'ici (404). */
    public static function commandeDeLaClinique(int $id): CommandeFournisseur
    {
        return CommandeFournisseur::query()->findOrFail($id);
    }

    /**
     * Les articles couverts par une commande ouverte (brouillon ou envoyée)
     * de la clinique ouverte.
     *
     * @return list<int>
     */
    private function produitsEnCommande(): array
    {
        return CommandeFournisseurLigne::query()
            ->whereIn('commande_fournisseur_id', CommandeFournisseur::query()
                ->select('id')
                ->where('statut', '!=', StatutCommandeFournisseur::Receptionnee))
            ->distinct()
            ->pluck('produit_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Sous le seuil dans la clinique, sans commande ouverte qui le couvre.
     *
     * @return Collection<int, Produit>
     */
    private function articlesSousSeuil(int $cliniqueId): Collection
    {
        return Produit::query()
            ->with(['stocks' => fn ($requete) => $requete->withoutGlobalScope('clinique')])
            ->whereNotIn('id', $this->produitsEnCommande())
            ->get()
            ->filter(fn (Produit $produit): bool => $produit->estSousLeSeuilDans($cliniqueId))
            ->values();
    }

    /** Remonter le stock de la clinique à deux fois le seuil, au moins le seuil. */
    private static function quantiteProposee(Produit $produit, int $cliniqueId): int
    {
        return max($produit->seuil_alerte * 2 - (int) $produit->stockDans($cliniqueId), $produit->seuil_alerte);
    }

    /**
     * Les statuts demandés, en liste (`draft,sent`) ou en tableau, sous leur
     * code ou leur nom français (`brouillon,envoyee`). Un statut inconnu est refusé.
     *
     * @return list<string>
     */
    private static function statutsDemandes(mixed $saisie): array
    {
        if ($saisie === null || $saisie === '') {
            return [];
        }
        $alias = ['brouillon' => 'draft', 'envoyee' => 'sent', 'envoyée' => 'sent', 'receptionnee' => 'rec', 'réceptionnée' => 'rec', 'recue' => 'rec', 'reçue' => 'rec'];
        $valeurs = is_array($saisie) ? $saisie : explode(',', (string) $saisie);
        $statuts = [];
        foreach ($valeurs as $valeur) {
            $cle = mb_strtolower(trim((string) $valeur));
            $statut = StatutCommandeFournisseur::tryFrom($alias[$cle] ?? $cle);
            if ($statut === null) {
                throw ValidationException::withMessages(['statut' => 'Statut inconnu : brouillon, envoyée ou reçue.']);
            }
            $statuts[] = $statut->value;
        }

        return array_values(array_unique($statuts));
    }

    /**
     * Le verrou de la clinique ouverte, toujours pris en premier dans les
     * transactions qui créent un brouillon (avant la numérotation) : deux
     * créations ne s'interbloquent jamais (vérification du 03.11.2026).
     */
    private static function verrouillerLaClinique(): void
    {
        DB::table('cliniques')->where('id', (int) app(ContexteClinique::class)->cliniqueId())->lockForUpdate()->first();
    }

    /** Brouillon numéroté pour la clinique du jeton (posée par le trait). */
    private function creerBrouillon(string $fournisseur): CommandeFournisseur
    {
        return CommandeFournisseur::create([
            'numero' => $this->numeroteur->attribuer(PieceNumerotee::CommandeFournisseur),
            'fournisseur' => $fournisseur,
            'statut' => StatutCommandeFournisseur::Brouillon,
        ]);
    }
}
