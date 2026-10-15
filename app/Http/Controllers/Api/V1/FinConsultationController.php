<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\ModePrescription;
use App\Enums\PieceNumerotee;
use App\Enums\StatutOrdonnance;
use App\Enums\StatutRendezVous;
use App\Enums\TypeLigneFacture;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\RendezVous\FinConsultationRequest;
use App\Http\Resources\V1\FactureResource;
use App\Http\Resources\V1\OrdonnanceResource;
use App\Http\Resources\V1\RendezVousResource;
use App\Models\Facture;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Prestation;
use App\Models\Produit;
use App\Models\ProduitVeterinaire;
use App\Models\RendezVous;
use App\Services\CreationFacture;
use App\Services\GestionnaireStock;
use App\Services\MutationRendezVous;
use App\Services\Numeroteur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Fin de consultation (« Ma journée », chemin 2 de docs/data-model.md
 * §3) — le geste composé : actes cochés → facture ; vaccin injecté →
 * carnet (upsert du même vaccin) + sortie de stock ; médicaments
 * prescrits → ordonnance au registre + sortie de stock + prescription
 * jointe ; le RDV passe en `fini` (audité). L'undo du front repasse par
 * les fenêtres d'annulation de la facture et de l'ordonnance.
 */
class FinConsultationController extends Controller
{
    public function __construct(
        private MutationRendezVous $mutation,
        private CreationFacture $creationFacture,
        private GestionnaireStock $stock,
        private Numeroteur $numeroteur,
    ) {}

    public function __invoke(FinConsultationRequest $request, RendezVous $rendezVous): RendezVousResource
    {
        $this->authorize('update', $rendezVous);

        if ($rendezVous->estTampon()) {
            throw ValidationException::withMessages(['rendez_vous' => 'Un tampon ne se facture jamais.']);
        }

        if (in_array($rendezVous->status, [StatutRendezVous::Fini, StatutRendezVous::Annule, StatutRendezVous::Noshow], true)) {
            throw ValidationException::withMessages(['status' => 'Seule une consultation du cycle actif se clôture.']);
        }

        if (Facture::where('rendez_vous_id', $rendezVous->id)->exists()) {
            throw ValidationException::withMessages(['rendez_vous' => 'Ce rendez-vous est déjà facturé — rouvrez sa facture en caisse.']);
        }

        $donnees = $request->validated();
        $patient = $rendezVous->patient;

        if ($patient === null && (isset($donnees['vaccination']) || ! empty($donnees['medicaments']))) {
            throw ValidationException::withMessages([
                'rendez_vous' => 'Vaccin et médicaments exigent une fiche patient — le rendez-vous n’en référence aucune.',
            ]);
        }

        // Le carnet est du domaine patients, l'ordonnance aussi : la
        // partie médicale du geste exige ce domaine en plus du planning.
        if (isset($donnees['vaccination'])) {
            $this->authorize('update', $patient);
        }

        if (! empty($donnees['medicaments'])) {
            $this->authorize('create', Ordonnance::class);
        }

        [$facture, $ordonnance] = DB::transaction(function () use ($request, $rendezVous, $donnees, $patient): array {
            $lignes = Prestation::findOrFail($donnees['prestations'])
                ->map(fn (Prestation $prestation): array => [
                    'libelle' => $prestation->nom,
                    'quantite' => 1,
                    'prix_chf' => (float) $prestation->prix,
                    'type' => TypeLigneFacture::Acte,
                ])
                ->all();

            $vaccinProduit = isset($donnees['vaccination']['produit_id'])
                ? Produit::findOrFail($donnees['vaccination']['produit_id'])
                : null;

            if ($vaccinProduit !== null) {
                $lignes[] = [
                    'libelle' => $vaccinProduit->nom,
                    'quantite' => 1,
                    'prix_chf' => (float) $vaccinProduit->prix,
                    'type' => TypeLigneFacture::Medicament,
                    'produit_id' => $vaccinProduit->id,
                ];
            }

            $medicaments = collect($donnees['medicaments'] ?? [])
                ->map(fn (array $medicament): array => [
                    'produit' => Produit::findOrFail($medicament['produit_id']),
                    'posologie' => trim((string) ($medicament['posologie'] ?? '')) ?: 'Selon notice',
                    'duree' => trim((string) ($medicament['duree'] ?? '')) ?: 'Selon besoin',
                ]);

            foreach ($medicaments as $medicament) {
                $lignes[] = [
                    'libelle' => $medicament['produit']->nom,
                    'quantite' => 1,
                    'prix_chf' => (float) $medicament['produit']->prix,
                    'type' => TypeLigneFacture::Medicament,
                    'produit_id' => $medicament['produit']->id,
                ];
            }

            // Les sorties de stock se font ici même : la facture naît
            // stock sorti (parité front — l'encaissement ne décrémente
            // pas une seconde fois).
            $facture = $this->creationFacture->nouvelle(
                [
                    'patient_id' => $rendezVous->patient_id,
                    'client_id' => $rendezVous->client_id,
                    'patient_name' => $rendezVous->patient_name,
                    'species' => $rendezVous->species,
                    'owner_name' => $rendezVous->owner_name,
                    'ligne_planning_id' => $rendezVous->ligne_planning_id,
                    'rendez_vous_id' => $rendezVous->id,
                    'stock_sorti' => true,
                ],
                $lignes,
                $request->user(),
            );

            $ordonnance = null;

            if ($medicaments->isNotEmpty()) {
                $ordonnance = Ordonnance::create([
                    'clinique_id' => app(ContexteClinique::class)->cliniqueId(),
                    'numero' => $this->numeroteur->attribuer(PieceNumerotee::Ordonnance),
                    'patient_id' => $patient->id,
                    'employe_id' => app(ContexteClinique::class)->employe()->id,
                    'signee_le' => today(),
                    'renouvellements' => 0,
                    'statut' => StatutOrdonnance::Active,
                ]);

                foreach ($medicaments as $medicament) {
                    $ordonnance->lignes()->create([
                        'produit_id' => $medicament['produit']->id,
                        'posologie' => $medicament['posologie'],
                        'duree' => $medicament['duree'],
                    ]);

                    $this->stock->sortir(
                        $medicament['produit'],
                        1,
                        'Sortie — ordonnance '.$ordonnance->numero,
                        $request->user(),
                        $ordonnance,
                    );

                    $facture->prescriptions()->create([
                        'nom' => $medicament['produit']->nom,
                        'posologie' => $medicament['posologie'],
                        'mode' => ModePrescription::Stock,
                    ]);
                }

                $facture->update(['ordonnance_id' => $ordonnance->id]);
            }

            if (isset($donnees['vaccination'])) {
                $this->inscrireAuCarnet($patient, $donnees['vaccination']);

                if ($vaccinProduit !== null) {
                    $this->stock->sortir(
                        $vaccinProduit,
                        1,
                        'Sortie — facture '.$facture->numero,
                        $request->user(),
                        $facture,
                    );
                }
            }

            $this->mutation->appliquer($rendezVous, ['status' => StatutRendezVous::Fini], $request->user());

            return [$facture, $ordonnance];
        });

        $resource = new RendezVousResource($rendezVous);
        $complements = [];

        if (Gate::allows('facturation')) {
            $complements['facture'] = new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
        }

        if ($ordonnance !== null && Gate::allows('patients')) {
            $complements['ordonnance'] = new OrdonnanceResource($ordonnance->load(['lignes.produit', 'employe']));
        }

        if ($complements !== []) {
            $resource->additional($complements);
        }

        return $resource;
    }

    /**
     * Injection du jour inscrite au carnet (16.10.2026) : une nouvelle
     * ligne à chaque fois (historique complet), reliée au produit du
     * catalogue quand la marque y est, avec ses valences ; le signataire
     * est l employé du jeton.
     *
     * @param  array<string, mixed>  $vaccination
     */
    private function inscrireAuCarnet(Patient $patient, array $vaccination): void
    {
        $produit = isset($vaccination['marque'])
            ? ProduitVeterinaire::query()->with('valences')->where('nom_court', $vaccination['marque'])->first()
            : null;

        $patient->vaccinations()->create([
            'produit_id' => $produit?->id,
            'nom' => $vaccination['nom'],
            'marque' => $vaccination['marque'] ?? null,
            'valences' => $produit?->valences->pluck('code')->all() ?: null,
            'lot' => $vaccination['lot'] ?? null,
            'injectee_le' => today(),
            'echeance_le' => $vaccination['echeance_le'] ?? null,
            'employe_id' => app(ContexteClinique::class)->employe()?->id,
        ]);
    }
}
