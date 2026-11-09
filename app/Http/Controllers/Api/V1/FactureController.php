<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\SettlementMethod;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Enums\VatContext;
use App\Http\Controllers\Api\V1\Concerns\ServedListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\EnregistrerFactureRequest;
use App\Http\Resources\V1\FactureResource;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Services\CreationFacture;
use App\Services\GestionnaireStock;
use App\Services\Isabv\Declarations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Les factures suivent le patient — NIVEAU ORGANISATION, lecture depuis
 * toutes les cliniques du jeton. Jamais de suppression hors fenêtre
 * d'annulation d'une création (même auteur, 20 s, encore due, jamais
 * clôturée ni assurée) — le stock éventuellement sorti est restauré par
 * mouvements inverses. Les factures issues d'un RDV naissent au passage
 * en « fini » (RendezVousStatutController).
 */
class FactureController extends Controller
{
    use ServedListing;

    /** Fenêtre d'annulation d'une création — couvre l'undo de 10 s du front plus la latence. */
    private const int FENETRE_ANNULATION_SECONDES = 20;

    /** Les tris de la liste (07.11.2026) */
    public const array SORTS = ['recent', 'old', 'amount'];

    /** Le TTC d'une facture en SQL, comme Facture::totalTtc */
    private const string SQL_TOTAL = 'round(coalesce((select sum(facture_lignes.quantite * facture_lignes.prix_chf) from facture_lignes where facture_lignes.facture_id = factures.id), 0), 2)';

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Facture::class);
        $request->validate([
            ...$this->listingRules(self::SORTS),
            'statut' => ['sometimes', 'nullable', Rule::enum(StatutFacture::class)],
            'a_regler' => ['sometimes', 'boolean'],
        ]);
        $motif = $this->searchPattern($request);

        $factures = Facture::query()
            ->with(['lignes', 'decompte', 'encaisseePar:id,name', 'settlements.reversal', 'settlements.recordedBy:id,name'])
            // Encaissée par Stripe (terminal, TWINT) : total vérifié, rien à rapprocher
            ->withExists(['paiements as paye_par_stripe' => fn (Builder $requete) => $requete->whereIn('statut', Facture::preuvesDeTerminal())])
            ->when($request->enum('statut', StatutFacture::class), fn (Builder $requete, StatutFacture $statut) => $requete->where('statut', $statut))
            ->when($request->integer('patient_id'), fn (Builder $requete, int $patient) => $requete->where('patient_id', $patient))
            // Les factures d'un propriétaire (« Encaisser » depuis le dossier d'un patient, 03.11.2026)
            ->when($request->integer('client_id'), fn (Builder $requete, int $client) => $requete->where('client_id', $client))
            ->when($request->integer('clinique_id'), fn (Builder $requete, int $clinique) => $requete->where('clinique_id', $clinique))
            // Jours du calendrier de la clinique (24.10.2026), pas de minuit UTC
            ->when($request->date('du'), fn (Builder $requete, $du) => $requete->where('emise_le', '>=', minuitLocal($du)))
            // « au » compris, comme le registre : jusqu'à minuit le soir de ce jour, heure de la clinique
            ->when($request->date('au'), fn (Builder $requete, $au) => $requete->where('emise_le', '<', minuitLocal($au)->addDay()))
            // Celles dont un règlement de caisse attend la clôture (09.11.2026 : le Z compte les règlements)
            ->when($request->boolean('a_cloturer'), fn (Builder $requete) => $requete
                ->where('clinique_id', app(ContexteClinique::class)->cliniqueId())
                ->whereHas('settlements', fn (Builder $q) => $q->whereNull('cloture_caisse_id')->whereIn('method', SettlementMethod::cashDeskValues())))
            // À régler (onglet du dossier, 07.11.2026) : due, et il reste quelque chose après ses règlements (acompte compris)
            ->when($request->boolean('a_regler'), fn (Builder $requete) => $requete
                ->where('statut', StatutFacture::Due)
                ->whereRaw(self::SQL_TOTAL.' - coalesce((select sum(st.amount) from settlements st where st.facture_id = factures.id), 0) > 0'))
            // Le numéro, les lignes, l'animal et le propriétaire écrits sur la facture
            ->when($motif !== null, fn (Builder $requete) => $requete->where(fn (Builder $clause) => $clause
                ->tap(fn (Builder $c) => $this->whereLikeAny($c, ['factures.numero', "coalesce(factures.patient_name, '')", "coalesce(factures.owner_name, '')"], $motif))
                ->orWhereHas('lignes', fn (Builder $ligne) => $this->whereLikeAny($ligne, ['facture_lignes.libelle'], $motif))))
            ->tap(fn (Builder $requete) => match ($this->sortOf($request, 'recent')) {
                'old' => $requete->orderBy('emise_le')->orderBy('id'),
                // Le TTC servi (Facture::totalTtc : la somme des lignes, arrondie)
                'amount' => $requete->orderByRaw(self::SQL_TOTAL.' desc')->orderByDesc('id'),
                default => $requete->latest('emise_le')->latest('id'),
            })
            ->paginate($this->parPage($request));

        return FactureResource::collection($factures);
    }

    /**
     * Facture manuelle (sortie d'hospitalisation, comptoir…). Quand la
     * fiche patient est référencée, la FK fait foi : les
     * dénormalisations sont réalignées dessus. Depuis le 23.10.2026, une
     * ligne de produit prend le prix du produit (une remise se fait par
     * une ligne négative) et le stock sort toujours à l'encaissement :
     * ni prix ni `stock_sorti` ne viennent du client.
     */
    public function store(EnregistrerFactureRequest $request, CreationFacture $creation): FactureResource
    {
        $this->authorize('create', Facture::class);

        $donnees = $request->validated();

        $attributs = [
            'patient_id' => $donnees['patient_id'] ?? null,
            'patient_name' => $donnees['patient_name'] ?? null,
            'species' => $donnees['species'] ?? null,
            'owner_name' => $donnees['owner_name'] ?? null,
            'ligne_planning_id' => $donnees['ligne_planning_id'] ?? null,
        ];

        if ($attributs['patient_id'] !== null) {
            $attributs = [...$attributs, ...Patient::findOrFail($attributs['patient_id'])->denormalisation()];
        }

        $produits = Produit::query()->whereIn('id', array_filter(array_column($donnees['lignes'], 'produit_id')))->get()->keyBy('id');
        // Sans consultation ni prescription : comme au comptoir (24.10.2026)
        foreach ($donnees['lignes'] as $i => $ligne) {
            if ($refus = $produits->get($ligne['produit_id'] ?? 0)?->refusVenteLibre()) {
                throw ValidationException::withMessages(["lignes.$i.produit_id" => $refus]);
            }
        }
        $lignes = array_map(function (array $ligne) use ($produits): array {
            $produit = isset($ligne['produit_id']) ? $produits->get($ligne['produit_id']) : null;

            return [
                'libelle' => $ligne['libelle'],
                'quantite' => $ligne['quantite'] ?? 1,
                'prix_chf' => $produit ? $produit->prix : $ligne['prix_chf'],
                'type' => $produit ? TypeLigneFacture::Medicament : $ligne['type'],
                'produit_id' => $produit?->id,
                // Un article y est délivré comme au comptoir ; le reste est un soin.
                // Le taux se calcule seul, jamais repris de la demande (03.11.2026)
                'vat_context' => $produit ? VatContext::Sale : VatContext::Care,
            ];
        }, $donnees['lignes']);

        $facture = $creation->nouvelle($attributs, $lignes, $request->user());

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte', 'settlements.reversal', 'settlements.recordedBy:id,name']));
    }

    public function show(Facture $facture): FactureResource
    {
        $this->authorize('view', $facture);

        // Avec l'en-tête de la clinique qui l'a émise (PDF d'une clinique sœur)
        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte', 'clinique.societe', 'client', 'encaisseePar:id,name', 'settlements.reversal', 'settlements.recordedBy:id,name']));
    }

    /**
     * Seule suppression tolérée : annuler une création dans sa fenêtre
     * d'undo — même auteur, facture encore due, jamais couverte par une
     * clôture ni un décompte. Les sorties de stock qu'elle a produites
     * sont restaurées par mouvements inverses.
     */
    public function destroy(Request $request, Facture $facture, GestionnaireStock $stock): Response
    {
        $this->authorize('delete', $facture);

        // La facture d'une sortie d'hospitalisation : le séjour resterait sorti sans
        // facture (décision de Jim, 03.11.2026) ; elle se corrige par un avoir
        if (Sejour::withoutGlobalScopes()->where('facture_id', $facture->id)->exists()) {
            throw ValidationException::withMessages(['facture' => 'Cette facture vient de la sortie d’un séjour : elle ne se supprime pas, ses lignes se corrigent en caisse.']);
        }

        $this->refuserSiNonSupprimable($request, $facture);

        DB::transaction(function () use ($request, $facture, $stock): void {
            // Deux suppressions en même temps (07.11.2026) : la facture relue sous
            // verrou ; déjà supprimée par l'autre, son stock est déjà revenu
            $verrouillee = Facture::withoutGlobalScopes()->whereKey($facture->id)->lockForUpdate()->first();
            if ($verrouillee === null) {
                return;
            }
            // Les contrôles refaits sous le verrou (07.11.2026) : un paiement par
            // terminal a pu démarrer entre-temps
            $this->refuserSiNonSupprimable($request, $verrouillee);
            $stock->inverserMouvementsDe(
                $facture,
                'Annulation facture '.$facture->numero,
                $request->user(),
            );

            // SI ABV : les déclarations de ses lignes s'effacent (08.11.2026)
            foreach ($facture->lignes()->get() as $ligne) {
                app(Declarations::class)->cancelFor($ligne);
            }
            $facture->delete();
        });

        return response()->noContent();
    }

    /**
     * Supprimable : aucun paiement en cours, et l'annulation immédiate d'une
     * création encore due, sans clôture ni décompte, par la même personne.
     * Vérifié avant, puis refait sous le verrou de la facture.
     */
    private function refuserSiNonSupprimable(Request $request, Facture $facture): void
    {
        // Un paiement Stripe attend le client : il serait encaissé sans facture
        if ($facture->aUnPaiementEnCours()) {
            throw ValidationException::withMessages(['paiements' => 'Un paiement est en cours sur cette facture : annulez-le d’abord.']);
        }

        $estDansLaFenetre = $facture->estDue()
            && ! $facture->aRecuUnReglement()
            && $facture->cloture_caisse_id === null
            && $facture->decompte()->doesntExist()
            && $facture->cree_par_id === $request->user()->id
            && $facture->created_at->gt(now()->subSeconds(self::FENETRE_ANNULATION_SECONDES));

        if (! $estDansLaFenetre) {
            throw ValidationException::withMessages([
                'facture' => 'Une facture ne se supprime pas, le journal est immuable. Seule l’annulation immédiate d’une création est tolérée.',
            ]);
        }
    }
}
