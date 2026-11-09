<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\ModePrescription;
use App\Enums\TypeLigneFacture;
use App\Enums\VatContext;
use App\Http\Controllers\Api\V1\Concerns\GesteDeCaisse;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\AjouterLigneFactureRequest;
use App\Http\Requests\V1\Facturation\ModifierLigneFactureRequest;
use App\Http\Resources\V1\FactureResource;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Prestation;
use App\Models\Produit;
use App\Models\Stock;
use App\Services\Facturation\LineVat;
use App\Services\Isabv\Declarations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Lignes d'une facture pendant l'encaissement (facture encore due,
 * depuis la clinique d'émission). Un acte s'ajoute au prix du
 * catalogue ; un médicament exige du stock local, s'ajoute au prix du
 * produit et joint la prescription « délivré par la clinique » — le
 * stock ne sort qu'au paiement (ou au « payer plus tard »). Une erreur
 * de saisie se corrige (quantité) ou se retire tant que la facture
 * n'est pas payée — même patron que les lignes d'un brouillon de
 * commande ; une ligne médicament dont le stock est déjà sorti
 * (« payer plus tard ») est figée, le journal de stock ferait foi.
 */
class FactureLigneController extends Controller
{
    use GesteDeCaisse;

    private const string TICKET_FIGE = 'Le ticket du comptoir ne se modifie pas : annulez la vente et refaites-la.';

    public function store(AjouterLigneFactureRequest $request, Facture $facture): FactureResource
    {
        $this->authorize('update', $facture);
        $this->assurerHorsComptoir($facture, self::TICKET_FIGE);
        $this->sansPaiementEnCours($facture);
        $this->assurerCliniqueDEmission($facture);
        $this->assurerFactureDue($facture);
        $this->assurerSansReglement($facture);
        $this->assurerMoisOuvert($facture);

        $donnees = $request->validated();

        DB::transaction(function () use ($facture, $donnees): void {
            if (isset($donnees['prestation_id'])) {
                $prestation = Prestation::findOrFail($donnees['prestation_id']);

                $facture->lignes()->create([
                    'libelle' => $prestation->nom,
                    'quantite' => 1,
                    'prix_chf' => $prestation->prix,
                    'type' => TypeLigneFacture::Acte,
                ]);

                return;
            }

            $produit = Produit::findOrFail($donnees['produit_id']);
            // Un stupéfiant ne se délivre pas depuis la caisse ; un produit sur
            // ordonnance y est prescrit, avec le droit de prescrire (24.10.2026)
            if ($produit->est_stupefiant) {
                throw ValidationException::withMessages(['produit_id' => $produit->refusVenteLibre()]);
            }
            // Un antibiotique de la liste de l'OSAV aussi : sa déclaration revient à qui prescrit (SI ABV, 08.11.2026)
            if ($produit->est_sur_ordonnance || $produit->isabvPreparation()?->must_transfer) {
                Gate::authorize(DomainePermission::Prescrire->value);
            }

            $quantiteLocale = (int) Stock::query()
                ->where('produit_id', $produit->id)
                ->where('clinique_id', app(ContexteClinique::class)->cliniqueId())
                ->value('quantite');

            if ($quantiteLocale < 1) {
                throw ValidationException::withMessages([
                    'produit_id' => 'Rupture de stock : établissez une ordonnance pharmacie.',
                ]);
            }

            // Remis au propriétaire (taux de vente) ou donné pendant le soin (taux du soin) ;
            // proposé selon l'article quand l'écran n'en dit rien (03.11.2026)
            $context = isset($donnees['vat_context']) ? VatContext::from($donnees['vat_context']) : LineVat::articleContext($produit, $facture->hasCare());
            $this->assurerUnSoin($facture, $context);

            $ligne = $facture->lignes()->create([
                'libelle' => $produit->nom,
                'quantite' => 1,
                'prix_chf' => $produit->prix,
                'type' => TypeLigneFacture::Medicament,
                'produit_id' => $produit->id,
                'vat_context' => $context,
            ]);

            // SI ABV (08.11.2026) : un antibiotique remis ou donné en caisse se déclare aussi, à compléter
            app(Declarations::class)->forProductUse($ligne, $facture->clinique_id, $facture->patient, $produit, app(ContexteClinique::class)->employe()?->id, aujourdhui());

            $facture->prescriptions()->create([
                'nom' => $produit->nom,
                'posologie' => $donnees['posologie'] ?? 'Posologie à préciser',
                'mode' => ModePrescription::Stock,
            ]);
        });

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte', 'settlements.reversal', 'settlements.recordedBy:id,name']));
    }

    public function update(ModifierLigneFactureRequest $request, Facture $facture, int $ligne): FactureResource
    {
        if ($request->has('quantite')) {
            $this->ligneModifiable($facture, $ligne)->update(['quantite' => $request->validated('quantite')]);
        }

        if ($request->has('vat_context')) {
            $this->changerContexte($facture, $ligne, VatContext::from($request->validated('vat_context')));
        }

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte', 'settlements.reversal', 'settlements.recordedBy:id,name']));
    }

    /**
     * Un article donné pendant le soin ou remis au propriétaire (TVA par
     * ligne, 03.11.2026) : le taux se recalcule au serveur. Le stock et la
     * prescription n'en dépendent pas : se dit aussi stock déjà sorti.
     */
    private function changerContexte(Facture $facture, int $id, VatContext $context): void
    {
        $this->assurerHorsComptoir($facture, self::TICKET_FIGE);
        $this->authorize('update', $facture);
        $this->sansPaiementEnCours($facture);
        $this->assurerCliniqueDEmission($facture);
        $this->assurerFactureDue($facture);
        $this->assurerSansReglement($facture);
        $this->assurerMoisOuvert($facture);

        $ligne = $facture->lignes()->findOrFail($id);
        if (! $ligne->isArticle()) {
            throw ValidationException::withMessages(['vat_context' => 'Seul un article se dit donné pendant le soin ou remis au propriétaire.']);
        }
        $this->assurerUnSoin($facture, $context);

        $ligne->vat_context = $context;
        $ligne->taux_tva = LineVat::forLine($ligne, $facture);
        $ligne->save();
    }

    /** Un article n'est donné pendant un soin que sur une facture qui en contient un. */
    private function assurerUnSoin(Facture $facture, VatContext $context): void
    {
        if ($context === VatContext::Care && ! $facture->hasCare()) {
            throw ValidationException::withMessages(['vat_context' => 'Cette facture ne contient aucun soin : l’article est remis au propriétaire.']);
        }
    }

    /**
     * Retrait d'une ligne ; la prescription jointe d'un médicament
     * part avec elle. Une facture garde au moins une ligne — une
     * facture vide se supprime (fenêtre d'undo), elle ne s'encaisse pas.
     */
    public function destroy(Facture $facture, int $ligne): FactureResource
    {
        $ligne = $this->ligneModifiable($facture, $ligne);

        if ($facture->lignes()->count() <= 1) {
            throw ValidationException::withMessages([
                'ligne' => 'Une facture garde au moins une ligne.',
            ]);
        }

        // Un antibiotique de la liste de l'OSAV : retirer sa ligne désactive sa déclaration, geste de qui prescrit
        if ($ligne->produit_id !== null && Produit::query()->find($ligne->produit_id)?->isabvPreparation()?->must_transfer) {
            Gate::authorize(DomainePermission::Prescrire->value);
        }

        DB::transaction(function () use ($facture, $ligne): void {
            if ($ligne->type === TypeLigneFacture::Medicament) {
                $facture->prescriptions()
                    ->where('mode', ModePrescription::Stock)
                    ->where('nom', $ligne->libelle)
                    ->limit(1)
                    ->delete();
            }

            // SI ABV : sa déclaration s'efface (ou se désactive à l'OSAV si elle est partie)
            app(Declarations::class)->cancelFor($ligne);
            $ligne->delete();

            // Plus aucun soin : ce qui était donné pendant le soin est remis au propriétaire,
            // au taux de vente recalculé (vérification du 04.11.2026)
            $facture->unsetRelation('lignes');
            if (! $facture->hasCare()) {
                foreach ($facture->lignes()->where('vat_context', VatContext::Care)->get() as $article) {
                    if (! $article->isArticle()) {
                        continue;
                    }
                    $article->vat_context = VatContext::Sale;
                    $article->taux_tva = LineVat::forLine($article, $facture);
                    $article->save();
                }
            }
        });

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte', 'settlements.reversal', 'settlements.recordedBy:id,name']));
    }

    /**
     * Gardes communes aux corrections : facture due, clinique
     * d'émission, ligne de CETTE facture, et stock pas encore sorti
     * pour un médicament.
     *
     * @throws ValidationException
     */
    private function ligneModifiable(Facture $facture, int $id): FactureLigne
    {
        $this->assurerHorsComptoir($facture, self::TICKET_FIGE);
        $this->authorize('update', $facture);
        $this->sansPaiementEnCours($facture);
        $this->assurerCliniqueDEmission($facture);
        $this->assurerFactureDue($facture);
        $this->assurerSansReglement($facture);
        $this->assurerMoisOuvert($facture);

        $ligne = $facture->lignes()->with('produit')->findOrFail($id);

        // Un médicament sur ordonnance (ou stupéfiant) se prescrit : sa quantité
        // ne se change ni ne se retire sans le droit de prescrire (24.10.2026)
        if ($ligne->produit?->est_sur_ordonnance || $ligne->produit?->est_stupefiant) {
            Gate::authorize(DomainePermission::Prescrire->value);
        }

        if ($ligne->type === TypeLigneFacture::Medicament && $facture->stock_sorti) {
            throw ValidationException::withMessages([
                'ligne' => 'Le stock de ce médicament est déjà sorti, corrigez par un ajustement de stock.',
            ]);
        }

        return $ligne;
    }

    /** La facture ne change pas pendant qu'un paiement Stripe attend le client (23.10.2026). */
    private function sansPaiementEnCours(Facture $facture): void
    {
        if ($facture->aUnPaiementEnCours()) {
            throw ValidationException::withMessages(['paiements' => 'Un paiement est en cours sur cette facture : annulez-le avant de la modifier.']);
        }
    }
}
