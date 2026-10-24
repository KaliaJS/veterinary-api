<?php

namespace Tests\Feature\Stupefiants;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\MouvementStupefiant;
use App\Models\Patient;
use App\Models\Produit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Comportement du registre (art. 62 OCStup, docs/data-model.md §16) :
 * mouvements immuables signés par l'employé du jeton, patient
 * obligatoire pour une sortie, solde par substance = Σ des mouvements,
 * export soldes + mouvements pour les contrôles cantonaux.
 */
class RegistreStupefiantTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $employe;

    private string $jeton;

    private Produit $substance;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $organisation = $this->clinique->societe->organisation_id;
        $this->substance = Produit::factory()->stupefiant()->create(['organisation_id' => $organisation]);
        $this->patient = Patient::factory()->create(['organisation_id' => $organisation]);
    }

    public function test_une_reception_s_enregistre_sans_patient_signee_par_l_employe_du_jeton(): void
    {
        $reponse = $this->postJson('/api/v1/stupefiants/mouvements', [
            'produit_id' => $this->substance->id,
            'quantite' => 20,
            'motif' => 'Réception grossiste — lot MT-4471',
        ], $this->enTetes($this->jeton));

        $reponse->assertCreated()
            ->assertJsonPath('data.patient_id', null)
            ->assertJsonPath('data.employe_id', $this->employe->id)
            ->assertJsonPath('data.clinique_id', $this->clinique->id)
            ->assertJsonPath('data.motif', 'Réception grossiste — lot MT-4471')
            ->assertJsonPath('data.produit.unite', 'ml');
    }

    public function test_une_sortie_exige_un_patient(): void
    {
        $this->postJson('/api/v1/stupefiants/mouvements', [
            'produit_id' => $this->substance->id,
            'quantite' => -0.4,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_une_sortie_s_enregistre_avec_patient_et_motif_par_defaut(): void
    {
        $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $this->substance->id, 'quantite' => 10], $this->enTetes($this->jeton))->assertCreated();

        $reponse = $this->postJson('/api/v1/stupefiants/mouvements', [
            'produit_id' => $this->substance->id,
            'quantite' => -0.4,
            'patient_id' => $this->patient->id,
        ], $this->enTetes($this->jeton));

        $reponse->assertCreated()
            ->assertJsonPath('data.patient_id', $this->patient->id)
            ->assertJsonPath('data.motif', 'Administration');
    }

    public function test_le_patient_est_ignore_sur_une_reception(): void
    {
        // Parité front : « — » pour une réception, même si un patient
        // est envoyé par erreur.
        $reponse = $this->postJson('/api/v1/stupefiants/mouvements', [
            'produit_id' => $this->substance->id,
            'quantite' => 10,
            'patient_id' => $this->patient->id,
        ], $this->enTetes($this->jeton));

        $reponse->assertCreated()->assertJsonPath('data.patient_id', null);

        $this->assertNull(MouvementStupefiant::withoutGlobalScopes()->sole()->patient_id);
    }

    public function test_une_quantite_nulle_est_refusee(): void
    {
        $this->postJson('/api/v1/stupefiants/mouvements', [
            'produit_id' => $this->substance->id,
            'quantite' => 0,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantite');
    }

    public function test_un_produit_non_stupefiant_est_refuse(): void
    {
        $ordinaire = Produit::factory()->medicament()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);

        $this->postJson('/api/v1/stupefiants/mouvements', [
            'produit_id' => $ordinaire->id,
            'quantite' => 5,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('produit_id');
    }

    public function test_le_solde_est_la_somme_des_mouvements_avec_alerte_stock_bas(): void
    {
        $organisation = $this->clinique->societe->organisation_id;
        $basse = Produit::factory()->stupefiant()->create(['organisation_id' => $organisation, 'nom' => 'Buprénorphine 0,3 mg/ml']);
        $this->substance->update(['nom' => 'Kétamine 100 mg/ml']);

        foreach ([[$this->substance, 48], [$this->substance, -1.2], [$basse, 9.5], [$basse, -0.6]] as [$produit, $quantite]) {
            MouvementStupefiant::factory()->create([
                'clinique_id' => $this->clinique->id,
                'produit_id' => $produit->id,
                'patient_id' => $quantite < 0 ? $this->patient->id : null,
                'quantite' => $quantite,
            ]);
        }

        $this->getJson('/api/v1/stupefiants/soldes', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nom', 'Buprénorphine 0,3 mg/ml')
            ->assertJsonPath('data.0.solde', 8.9)
            ->assertJsonPath('data.0.stock_bas', true)
            ->assertJsonPath('data.1.nom', 'Kétamine 100 mg/ml')
            ->assertJsonPath('data.1.solde', 46.8)
            ->assertJsonPath('data.1.stock_bas', false);
    }

    public function test_le_registre_s_exporte_avec_soldes_et_mouvements_filtres_par_periode(): void
    {
        MouvementStupefiant::factory()->create([
            'clinique_id' => $this->clinique->id,
            'produit_id' => $this->substance->id,
            'quantite' => 25,
            'created_at' => now()->subDays(40),
        ]);
        MouvementStupefiant::factory()->create([
            'clinique_id' => $this->clinique->id,
            'produit_id' => $this->substance->id,
            'patient_id' => $this->patient->id,
            'quantite' => -0.4,
            'created_at' => now()->subDay(),
        ]);

        $complet = $this->getJson('/api/v1/stupefiants/registre', $this->enTetes($this->jeton))
            ->assertOk();

        $complet->assertJsonCount(2, 'data.mouvements');
        // Le solde ne dépend pas de la période : il reste la somme totale.
        $complet->assertJsonPath('data.soldes.0.solde', 24.6);

        $this->getJson('/api/v1/stupefiants/registre?du='.now()->subDays(7)->toDateString(), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data.mouvements')
            ->assertJsonPath('data.soldes.0.solde', 24.6);
    }

    public function test_le_journal_est_immuable(): void
    {
        $mouvement = MouvementStupefiant::factory()->create([
            'clinique_id' => $this->clinique->id,
            'produit_id' => $this->substance->id,
        ]);

        // Aucune route unitaire : ni modification ni suppression.
        $this->putJson("/api/v1/stupefiants/mouvements/{$mouvement->id}", [], $this->enTetes($this->jeton))
            ->assertNotFound();
        $this->deleteJson("/api/v1/stupefiants/mouvements/{$mouvement->id}", [], $this->enTetes($this->jeton))
            ->assertNotFound();

        // La collection ne répond qu'en lecture et en création.
        $this->putJson('/api/v1/stupefiants/mouvements', [], $this->enTetes($this->jeton))
            ->assertStatus(405);
    }

    /** Le registre d'un jour suit le calendrier de la clinique : 23 h 30 à Zurich est encore ce jour-là. */
    public function test_l_export_d_un_jour_suit_le_calendrier_de_la_clinique(): void
    {
        $this->clinique->update(['fuseau_horaire' => 'Europe/Zurich']);
        $jour = CarbonImmutable::parse('2026-07-14', 'Europe/Zurich');
        $mouvement = fn (CarbonImmutable $quand) => MouvementStupefiant::withoutEvents(fn () => MouvementStupefiant::query()->create([
            'clinique_id' => $this->clinique->id, 'produit_id' => $this->substance->id, 'quantite' => 5, 'motif' => 'Réception grossiste', 'created_at' => $quand->utc(),
        ]));
        $tard = $mouvement($jour->setTime(23, 30));
        $mouvement($jour->addDay()->setTime(0, 30));
        $mouvement($jour->subDay()->setTime(23, 30));

        $ids = collect($this->getJson('/api/v1/stupefiants/registre?du=2026-07-14&au=2026-07-14', $this->enTetes($this->jeton))->assertOk()->json('data.mouvements'))->pluck('id');
        $this->assertSame([$tard->id], $ids->all());
    }
}
