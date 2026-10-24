<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\EnregistrerFactureRequest;
use App\Http\Resources\V1\FactureResource;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\Produit;
use App\Services\CreationFacture;
use App\Services\GestionnaireStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
    /** Fenêtre d'annulation d'une création — couvre l'undo de 10 s du front plus la latence. */
    private const int FENETRE_ANNULATION_SECONDES = 20;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Facture::class);

        $factures = Facture::query()
            ->with(['lignes', 'decompte', 'encaisseePar:id,name'])
            // Encaissée par Stripe (terminal, TWINT) : total vérifié, rien à rapprocher
            ->withExists(['paiements as paye_par_stripe' => fn (Builder $requete) => $requete->whereIn('statut', Facture::preuvesDeTerminal())])
            ->when($request->enum('statut', StatutFacture::class), fn (Builder $requete, StatutFacture $statut) => $requete->where('statut', $statut))
            ->when($request->integer('patient_id'), fn (Builder $requete, int $patient) => $requete->where('patient_id', $patient))
            ->when($request->integer('clinique_id'), fn (Builder $requete, int $clinique) => $requete->where('clinique_id', $clinique))
            // Jours du calendrier de la clinique (24.10.2026), pas de minuit UTC
            ->when($request->date('du'), fn (Builder $requete, $du) => $requete->where('emise_le', '>=', minuitLocal($du)))
            ->when($request->date('au'), fn (Builder $requete, $au) => $requete->where('emise_le', '<', minuitLocal($au)))
            ->when($request->boolean('a_cloturer'), fn (Builder $requete) => $requete
                ->where('statut', StatutFacture::Payee)
                ->whereNull('cloture_caisse_id')
                ->where('clinique_id', app(ContexteClinique::class)->cliniqueId()))
            ->latest('emise_le')
            ->latest('id')
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
            ];
        }, $donnees['lignes']);

        $facture = $creation->nouvelle($attributs, $lignes, $request->user());

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
    }

    public function show(Facture $facture): FactureResource
    {
        $this->authorize('view', $facture);

        // Avec l'en-tête de la clinique qui l'a émise (PDF d'une clinique sœur)
        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte', 'clinique.societe', 'encaisseePar:id,name']));
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

        // Un paiement Stripe attend le client : il serait encaissé sans facture
        if ($facture->aUnPaiementEnCours()) {
            throw ValidationException::withMessages(['paiements' => 'Un paiement est en cours sur cette facture : annulez-le d’abord.']);
        }

        $estDansLaFenetre = $facture->estDue()
            && $facture->cloture_caisse_id === null
            && $facture->decompte()->doesntExist()
            && $facture->cree_par_id === $request->user()->id
            && $facture->created_at->gt(now()->subSeconds(self::FENETRE_ANNULATION_SECONDES));

        if (! $estDansLaFenetre) {
            throw ValidationException::withMessages([
                'facture' => 'Une facture ne se supprime pas, le journal est immuable. Seule l’annulation immédiate d’une création est tolérée.',
            ]);
        }

        DB::transaction(function () use ($request, $facture, $stock): void {
            $stock->inverserMouvementsDe(
                $facture,
                'Annulation facture '.$facture->numero,
                $request->user(),
            );

            $facture->delete();
        });

        return response()->noContent();
    }
}
