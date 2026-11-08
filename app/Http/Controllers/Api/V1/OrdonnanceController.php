<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\PieceNumerotee;
use App\Enums\StatutOrdonnance;
use App\Http\Controllers\Api\V1\Concerns\ServedListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Ordonnances\EnregistrerOrdonnanceRequest;
use App\Http\Resources\V1\OrdonnanceResource;
use App\Models\MouvementStupefiant;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Services\GestionnaireStock;
use App\Services\Isabv\Declarations;
use App\Services\Numeroteur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Registre des ordonnances (docs/data-model.md §12). La signature crée
 * l'ordonnance ET sort le stock — la quantité à délivrer de chaque ligne, clinique du
 * jeton, sans blocage en rupture (l'écart réel est tracé, parité
 * front). Jamais de modification après signature ; la seule suppression
 * est l'annulation d'une signature dans sa fenêtre, qui restaure le
 * stock par mouvements inverses.
 */
class OrdonnanceController extends Controller
{
    use ServedListing;

    /** Fenêtre d'annulation d'une signature — couvre l'undo de 10 s du front plus la latence. */
    private const int FENETRE_ANNULATION_SECONDES = 20;

    public function __construct(
        private Numeroteur $numeroteur,
        private GestionnaireStock $stock,
        private Declarations $declarations,
    ) {}

    /** Les tris de l'écran Ordonnances (07.11.2026) */
    public const array SORTS = ['recent', 'old', 'patient'];

    /**
     * Le registre de l'organisation, trié, filtré et cherché par le serveur
     * sur toutes les ordonnances, page après page (tri au-delà de 100
     * lignes, 07.11.2026) ; `meta.facettes` : le nombre d'ordonnances de
     * chaque état et celui des renouvellements demandés, à traiter, quels
     * que soient la recherche et le filtre.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Ordonnance::class);
        $request->validate([
            ...$this->listingRules(self::SORTS),
            'statut' => ['sometimes', 'nullable', Rule::enum(StatutOrdonnance::class)],
        ]);
        $motif = $this->searchPattern($request);

        $ordonnances = Ordonnance::query()
            ->with([...Ordonnance::relationsDocument(), 'antibioticDeclarations'])
            ->when($request->enum('statut', StatutOrdonnance::class), fn (Builder $requete, StatutOrdonnance $statut) => $requete->where('statut', $statut))
            ->when($request->integer('patient_id'), fn (Builder $requete, int $patient) => $requete->where('patient_id', $patient))
            // Le numéro, l'animal, son propriétaire, les médicaments
            ->when($motif !== null, fn (Builder $requete) => $requete->where(fn (Builder $clause) => $clause
                ->tap(fn (Builder $c) => $this->whereLikeAny($c, ['ordonnances.numero'], $motif))
                ->orWhereHas('patient', fn (Builder $patient) => $patient->where(fn (Builder $p) => $p
                    ->tap(fn (Builder $pp) => $this->whereLikeAny($pp, ['patients.nom'], $motif))
                    ->orWhereHas('client', fn (Builder $client) => $this->whereLikeAny($client, ['clients.nom', "coalesce(clients.prenom, '')"], $motif))))
                ->orWhereHas('lignes.produit', fn (Builder $produit) => $this->whereLikeAny($produit, ['produits.nom'], $motif))))
            ->tap(fn (Builder $requete) => $this->sortPrescriptions($requete, $this->sortOf($request, 'recent')))
            ->paginate($this->parPage($request));
        // Les principes actifs des médicaments de la page, en une requête (allergies, 03.11.2026)
        Produit::withCatalogueSubstances($ordonnances->getCollection()->flatMap(fn (Ordonnance $o) => $o->lignes->pluck('produit')));

        return OrdonnanceResource::collection($ordonnances)->additional([
            'meta' => ['facettes' => $this->prescriptionFacets($request)],
        ]);
    }

    /**
     * La date de signature, ou le nom de l'animal (puis la plus récente) ; le
     * numéro départage, toujours.
     *
     * @param  Builder<Ordonnance>  $requete
     */
    private function sortPrescriptions(Builder $requete, string $tri): void
    {
        match ($tri) {
            'old' => $requete->orderBy('signee_le')->orderBy('id'),
            'patient' => $requete
                ->orderBy(Patient::withoutGlobalScopes()->selectRaw('lower(patients.nom)')->whereColumn('patients.id', 'ordonnances.patient_id')->limit(1))
                ->orderByDesc('signee_le')
                ->orderByDesc('id'),
            default => $requete->orderByDesc('signee_le')->orderByDesc('id'),
        };
    }

    /**
     * Les compteurs du filtre et la pastille du menu (les renouvellements
     * demandés par les propriétaires, encore à traiter), en une requête.
     *
     * @return array{tous: int, active: int, stop: int, renouvellements: int}
     */
    private function prescriptionFacets(Request $request): array
    {
        $ligne = Ordonnance::query()
            ->when($request->integer('patient_id'), fn (Builder $requete, int $patient) => $requete->where('patient_id', $patient))
            ->selectRaw(implode(', ', [
                'count(*) as tous',
                'sum(case when statut = ? then 1 else 0 end) as active',
                'sum(case when statut = ? then 1 else 0 end) as stop',
                'sum(case when statut = ? and exists (select 1 from demandes_renouvellement_ordonnance d where d.ordonnance_id = ordonnances.id and d.traitee_le is null) then 1 else 0 end) as renouvellements',
            ]), [StatutOrdonnance::Active->value, StatutOrdonnance::Cloturee->value, StatutOrdonnance::Active->value])
            ->toBase()
            ->first();

        return collect(['tous', 'active', 'stop', 'renouvellements'])
            ->mapWithKeys(fn (string $cle): array => [$cle => (int) ($ligne->{$cle} ?? 0)])
            ->all();
    }

    public function store(EnregistrerOrdonnanceRequest $request): OrdonnanceResource
    {
        $this->authorize('create', Ordonnance::class);

        $donnees = $request->validated();
        $contexte = app(ContexteClinique::class);

        $ordonnance = DB::transaction(function () use ($request, $donnees, $contexte): Ordonnance {
            $ordonnance = Ordonnance::create([
                'clinique_id' => $contexte->cliniqueId(),
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::Ordonnance),
                'patient_id' => $donnees['patient_id'],
                'employe_id' => $contexte->employe()->id,
                'signee_le' => aujourdhui(),
                'renouvellements' => $donnees['renouvellements'] ?? 0,
                'statut' => StatutOrdonnance::Active,
                'note' => $donnees['note'] ?? null,
            ]);

            foreach ($donnees['lignes'] as $ligne) {
                $ordonnance->lignes()->create([
                    'produit_id' => $ligne['produit_id'],
                    'quantite' => $ligne['quantite'] ?? 1,
                    'posologie' => $ligne['posologie'],
                    'duree' => $ligne['duree'] ?? 'Selon besoin',
                ]);

                // La quantité à délivrer — sans blocage en rupture : le
                // journal trace l'écart réel (parité front).
                $this->stock->sortir(
                    Produit::findOrFail($ligne['produit_id']),
                    $ligne['quantite'] ?? 1,
                    'Sortie : ordonnance '.$ordonnance->numero,
                    $request->user(),
                    $ordonnance,
                );
            }

            // SI ABV (08.11.2026) : les antibiotiques de la liste de l'OSAV se déclarent
            $this->declarations->forOrdonnance($ordonnance, $donnees['isabv'] ?? [], array_map(fn (array $ligne) => $ligne['isabv'] ?? null, $donnees['lignes']));

            return $ordonnance;
        });

        return new OrdonnanceResource($ordonnance->load([...Ordonnance::relationsDocument(), 'antibioticDeclarations']));
    }

    public function show(Ordonnance $ordonnance): OrdonnanceResource
    {
        $this->authorize('view', $ordonnance);

        return new OrdonnanceResource($ordonnance->load([...Ordonnance::relationsDocument(), 'antibioticDeclarations']));
    }

    /**
     * Annulation d'une signature dans sa fenêtre — seule suppression
     * tolérée du registre : même signataire, aucun geste depuis, stock
     * restauré par mouvements inverses. Si l'ordonnance annulée était un
     * renouvellement, la précédente redevient active (parité front).
     */
    public function destroy(Request $request, Ordonnance $ordonnance): Response
    {
        $this->authorize('delete', $ordonnance);

        $estDansLaFenetre = $ordonnance->statut === StatutOrdonnance::Active
            && $ordonnance->employe_id === app(ContexteClinique::class)->employe()?->id
            && $ordonnance->created_at->gt(now()->subSeconds(self::FENETRE_ANNULATION_SECONDES));

        if (! $estDansLaFenetre) {
            throw ValidationException::withMessages([
                'ordonnance' => 'Une ordonnance signée ne se supprime pas, clôturez-la. Seule l’annulation immédiate d’une signature est tolérée.',
            ]);
        }
        // Une remise de stupéfiant s'appuie sur elle : le registre garde sa pièce (vérification du 01.11.2026)
        if (MouvementStupefiant::withoutGlobalScopes()->where('ordonnance_id', $ordonnance->id)->exists()) {
            throw ValidationException::withMessages([
                'ordonnance' => 'Cette ordonnance a servi à une remise inscrite au registre des stupéfiants : elle ne s’annule plus, clôturez-la.',
            ]);
        }

        DB::transaction(function () use ($request, $ordonnance): void {
            // Sous verrou : un renouvellement au même instant depuis un autre
            // poste la clôt d'abord, et l'annulation est alors refusée
            if (Ordonnance::query()->whereKey($ordonnance->id)->lockForUpdate()->value('statut') !== StatutOrdonnance::Active) {
                throw ValidationException::withMessages([
                    'ordonnance' => 'Cette ordonnance a été renouvelée ou clôturée entre-temps : elle ne s’annule plus.',
                ]);
            }

            $this->stock->inverserMouvementsDe(
                $ordonnance,
                'Annulation ordonnance '.$ordonnance->numero,
                $request->user(),
            );

            $ordonnance->precedente?->update(['statut' => StatutOrdonnance::Active]);

            // Ses déclarations SI ABV, pas encore parties (fenêtre d'annulation), s'effacent
            $this->declarations->cancelFor($ordonnance);

            $ordonnance->delete();
        });

        return response()->noContent();
    }
}
