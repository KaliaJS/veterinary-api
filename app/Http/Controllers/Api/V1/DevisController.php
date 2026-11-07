<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\PieceNumerotee;
use App\Enums\StatutDevis;
use App\Http\Controllers\Api\V1\Concerns\ServedListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Devis\ApercuDevisRequest;
use App\Http\Requests\V1\Devis\EnregistrerDevisRequest;
use App\Http\Requests\V1\Devis\ModifierDevisRequest;
use App\Http\Resources\V1\DevisResource;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Patient;
use App\Services\Numeroteur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Devis (docs/data-model.md §7) — niveau organisation. Seul un
 * brouillon se modifie et se supprime ; le filtre de statut comprend
 * `expire`, dérivé d'un envoyé hors validité (jamais stocké).
 */
class DevisController extends Controller
{
    use ServedListing;

    public function __construct(private Numeroteur $numeroteur) {}

    /** Les tris de l'écran Devis (07.11.2026) */
    public const array SORTS = ['recent', 'old', 'amount', 'patient'];

    /** Les statuts effectifs du filtre (`expire` est dérivé) */
    public const array STATUSES = ['brouillon', 'envoye', 'accepte', 'refuse', StatutDevis::EXPIRE];

    /**
     * Les devis de l'organisation, triés, filtrés et cherchés par le serveur
     * sur tous les devis, page après page (tri au-delà de 100 lignes,
     * 07.11.2026) ; `meta.facettes` : le nombre de devis de chaque statut
     * effectif, quels que soient la recherche et le filtre.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Devis::class);
        $request->validate([
            ...$this->listingRules(self::SORTS),
            'statut' => ['sometimes', 'nullable', 'string', Rule::in(self::STATUSES)],
        ]);

        $statut = $request->string('statut')->toString();
        $motif = $this->searchPattern($request);
        $aujourdhui = aujourdhui()->toDateString();

        $devis = Devis::query()
            ->with(['lignes.produit' => fn ($requete) => $requete->withoutGlobalScopes(), 'patient.client', 'clinique.societe'])
            ->when($statut === StatutDevis::EXPIRE, fn (Builder $requete) => $requete
                ->where('statut', StatutDevis::Envoye)
                ->whereDate('valide_jusquau', '<', $aujourdhui))
            ->when($statut === StatutDevis::Envoye->value, fn (Builder $requete) => $requete
                ->where('statut', StatutDevis::Envoye)
                ->where(fn (Builder $clause) => $clause
                    ->whereNull('valide_jusquau')
                    ->orWhereDate('valide_jusquau', '>=', $aujourdhui)))
            ->when(in_array($statut, [StatutDevis::Brouillon->value, StatutDevis::Accepte->value, StatutDevis::Refuse->value], true),
                fn (Builder $requete) => $requete->where('statut', $statut))
            ->when($request->integer('patient_id'), fn (Builder $requete, int $patient) => $requete->where('patient_id', $patient))
            // Le numéro, le motif, l'animal et son propriétaire
            ->when($motif !== null, fn (Builder $requete) => $requete->where(fn (Builder $clause) => $clause
                ->tap(fn (Builder $c) => $this->whereLikeAny($c, ['devis.numero', "coalesce(devis.motif, '')"], $motif))
                ->orWhereHas('patient', fn (Builder $patient) => $patient->where(fn (Builder $p) => $p
                    ->tap(fn (Builder $pp) => $this->whereLikeAny($pp, ['patients.nom'], $motif))
                    ->orWhereHas('client', fn (Builder $client) => $this->whereLikeAny($client, ['clients.nom', "coalesce(clients.prenom, '')"], $motif))))))
            ->tap(fn (Builder $requete) => $this->sortQuotes($requete, $this->sortOf($request, 'recent')))
            ->paginate($this->parPage($request));

        return DevisResource::collection($devis)->additional([
            'meta' => ['facettes' => $this->quoteFacets($request, $aujourdhui)],
        ]);
    }

    /**
     * Le tri : la date d'émission ; le montant, le TTC servi (la somme des
     * lignes, remisée, arrondie comme Devis::totalTtc) ; le nom de l'animal
     * (un brouillon sans animal en fin). Le numéro départage, toujours.
     *
     * @param  Builder<Devis>  $requete
     */
    private function sortQuotes(Builder $requete, string $tri): void
    {
        match ($tri) {
            'old' => $requete->orderBy('emis_le')->orderBy('id'),
            'amount' => $requete
                ->orderByRaw('round(round(coalesce((select sum(devis_lignes.quantite * devis_lignes.prix_chf) from devis_lignes where devis_lignes.devis_id = devis.id), 0), 2) * (100 - devis.remise_pct) / 100.0, 2) desc')
                ->orderByDesc('id'),
            'patient' => $requete
                ->orderByRaw('devis.patient_id is null')
                ->orderBy(Patient::withoutGlobalScopes()->selectRaw('lower(patients.nom)')->whereColumn('patients.id', 'devis.patient_id')->limit(1))
                ->orderByDesc('emis_le')
                ->orderByDesc('id'),
            default => $requete->orderByDesc('emis_le')->orderByDesc('id'),
        };
    }

    /**
     * Le nombre de devis de chaque statut effectif (les compteurs du filtre
     * et la pastille du menu), sur tous les devis (de l'animal demandé),
     * en une requête.
     *
     * @return array{tous: int, brouillon: int, envoye: int, accepte: int, refuse: int, expire: int}
     */
    private function quoteFacets(Request $request, string $aujourdhui): array
    {
        $ligne = Devis::query()
            ->when($request->integer('patient_id'), fn (Builder $requete, int $patient) => $requete->where('patient_id', $patient))
            ->selectRaw(implode(', ', [
                'count(*) as tous',
                'sum(case when statut = ? then 1 else 0 end) as brouillon',
                'sum(case when statut = ? and (valide_jusquau is null or date(valide_jusquau) >= ?) then 1 else 0 end) as envoye',
                'sum(case when statut = ? then 1 else 0 end) as accepte',
                'sum(case when statut = ? then 1 else 0 end) as refuse',
                'sum(case when statut = ? and date(valide_jusquau) < ? then 1 else 0 end) as expire',
            ]), [
                StatutDevis::Brouillon->value,
                StatutDevis::Envoye->value, $aujourdhui,
                StatutDevis::Accepte->value,
                StatutDevis::Refuse->value,
                StatutDevis::Envoye->value, $aujourdhui,
            ])
            ->toBase()
            ->first();

        return collect(['tous', 'brouillon', 'envoye', 'accepte', 'refuse', StatutDevis::EXPIRE])
            ->mapWithKeys(fn (string $cle): array => [$cle => (int) ($ligne->{$cle} ?? 0)])
            ->all();
    }

    public function store(EnregistrerDevisRequest $request): DevisResource
    {
        $this->authorize('create', Devis::class);

        $donnees = $request->validated();

        $devis = DB::transaction(function () use ($donnees): Devis {
            $devis = Devis::create([
                // La clinique qui l'établit : celle du jeton (en-tête des pièces)
                'clinique_id' => app(ContexteClinique::class)->cliniqueId(),
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::Devis),
                'emis_le' => aujourdhui(),
                'patient_id' => $donnees['patient_id'] ?? null,
                'motif' => $donnees['motif'] ?? 'Devis de soins',
                'remise_pct' => $donnees['remise_pct'] ?? 0,
                'alea_pct' => $donnees['alea_pct'] ?? 0,
                'validite_jours' => $donnees['validite_jours'] ?? 30,
                'acompte_pct' => $donnees['acompte_pct'] ?? 0,
                'conditions' => $donnees['conditions'] ?? null,
                'statut' => StatutDevis::Brouillon,
            ]);

            $this->enregistrerLignes($devis, $donnees['lignes']);

            return $devis;
        });

        return new DevisResource($devis->load(['lignes', 'patient.client', 'clinique.societe']));
    }

    /**
     * Le devis en cours de saisie chiffré sans être enregistré (24.10.2026) :
     * l'écran du formulaire montre les montants du serveur (sous-total,
     * remise, TVA, fourchette, acompte, total de chaque ligne), les mêmes
     * que ceux du devis enregistré.
     */
    public function apercu(ApercuDevisRequest $request): JsonResponse
    {
        $this->authorize('create', Devis::class);

        $donnees = $request->validated();
        $devis = new Devis([
            'remise_pct' => $donnees['remise_pct'] ?? 0,
            'alea_pct' => $donnees['alea_pct'] ?? 0,
            'acompte_pct' => $donnees['acompte_pct'] ?? 0,
            'patient_id' => $donnees['patient_id'] ?? null,
        ]);
        $devis->setRelation('clinique', app(ContexteClinique::class)->clinique()?->loadMissing('societe'));
        $lignes = collect($donnees['lignes'])->map(fn (array $ligne): DevisLigne => new DevisLigne([
            'quantite' => $ligne['quantite'] ?? 1,
            'prix_chf' => $ligne['prix_chf'],
            'produit_id' => $ligne['produit_id'] ?? null,
            'vat_context' => $ligne['vat_context'] ?? null,
        ]));
        $devis->setRelation('lignes', new EloquentCollection($lignes->all()));

        return response()->json(['data' => [
            'lignes' => $lignes->map(fn (DevisLigne $ligne): array => ['total_chf' => round($ligne->quantite * (float) $ligne->prix_chf, 2)])->values()->all(),
            'sous_total' => $devis->sousTotal(),
            'montant_remise' => $devis->montantRemise(),
            'total_ttc' => $devis->totalTtc(),
            'montant_tva' => $devis->montantTva(),
            'total_ht' => $devis->totalHt(),
            'taux_tva' => $devis->tauxUnique(),
            'vat_breakdown' => $devis->vatBreakdown(),
            'total_avec_alea' => $devis->totalAvecAlea(),
            'montant_acompte' => $devis->montantAcompte(),
        ]]);
    }

    public function show(Devis $devis): DevisResource
    {
        $this->authorize('view', $devis);

        return new DevisResource($devis->load(['lignes', 'patient.client', 'clinique.societe']));
    }

    public function update(ModifierDevisRequest $request, Devis $devis): DevisResource
    {
        $this->authorize('update', $devis);

        if ($devis->statut !== StatutDevis::Brouillon) {
            throw ValidationException::withMessages(['statut' => 'Seul un brouillon se modifie.']);
        }

        $donnees = $request->validated();

        DB::transaction(function () use ($devis, $donnees): void {
            $devis->update(collect($donnees)->only([
                'patient_id', 'motif', 'remise_pct', 'alea_pct',
                'validite_jours', 'acompte_pct', 'conditions',
            ])->all());

            if (isset($donnees['lignes'])) {
                $devis->lignes()->delete();
                $this->enregistrerLignes($devis, $donnees['lignes']);
            }
        });

        return new DevisResource($devis->load(['lignes', 'patient.client', 'clinique.societe']));
    }

    /** Suppression tolérée : le brouillon seulement. */
    public function destroy(Devis $devis): Response
    {
        $this->authorize('delete', $devis);

        if ($devis->statut !== StatutDevis::Brouillon) {
            throw ValidationException::withMessages([
                'statut' => 'Seul un brouillon se supprime, un devis envoyé se refuse ou expire.',
            ]);
        }

        $devis->delete();

        return response()->noContent();
    }

    /** @param list<array<string, mixed>> $lignes */
    private function enregistrerLignes(Devis $devis, array $lignes): void
    {
        foreach ($lignes as $ligne) {
            $devis->lignes()->create([
                'libelle' => $ligne['libelle'],
                'quantite' => $ligne['quantite'] ?? 1,
                'prix_chf' => $ligne['prix_chf'],
                'type' => $ligne['type'],
                'produit_id' => $ligne['produit_id'] ?? null,
                'vat_context' => $ligne['vat_context'] ?? null,
            ]);
        }
    }
}
