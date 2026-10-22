<?php

namespace Tests\Feature\Organisation;

use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\Role;
use App\Models\Sejour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Services d'une clinique, cochés par l'administrateur : ils ouvrent
 * les modules de l'espace vétérinaire (en plus de la permission du
 * rôle) et paraissent dans la session. Une clinique créée n'en a aucun.
 */
class ServicesCliniqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->sansServices()->create(['nombre_boxes' => 0]);
        ['employe' => $administrateur, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $administrateur->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->clinique->societe->organisation_id)
                ->where('nom', RolesParDefaut::ADMINISTRATEUR)
                ->value('id'),
        ]);
    }

    /** @return array<string, mixed> */
    private function reglage(array $services, array $especes = ['CH', 'CT'], ?int $boxes = null, bool $affiche = true): array
    {
        return ['services' => $services, 'especes' => $especes, 'nombre_boxes' => $boxes, 'affiche_veterinaires' => $affiche];
    }

    public function test_un_module_s_ouvre_quand_l_administrateur_coche_le_service(): void
    {
        $entetes = $this->enTetes($this->jeton);

        // Sans service : modules fermés, session sans service
        $this->getJson('/api/v1/hospitalisation/sejours', $entetes)->assertForbidden();
        $this->getJson('/api/v1/bloc/interventions', $entetes)->assertForbidden();
        $this->getJson('/api/v1/auth/moi', $entetes)->assertJsonPath('data.clinique.services', []);

        $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/services", $this->reglage([
            ['service' => 'hospitalisation'],
            ['service' => 'urgences', 'modalite' => 'permanence'],
        ], boxes: 6, affiche: false), $entetes)
            ->assertOk()
            ->assertJsonPath('data.nombre_boxes', 6)
            ->assertJsonPath('data.services', [
                ['service' => 'hospitalisation', 'modalite' => null],
                ['service' => 'urgences', 'modalite' => 'permanence'],
            ])
            ->assertJsonPath('data.especes', ['CH', 'CT'])
            ->assertJsonPath('data.affiche_veterinaires', false)
            // Rendez-vous sans compte : non par défaut, inchangé s'il n'est pas envoyé
            ->assertJsonPath('data.rdv_sans_compte', false)
            // Rendez-vous en ligne réservés aussitôt, sauf si la clinique les valide ;
            // les urgences attendent la clinique ; pas d'acompte, 24 h pour l'annuler
            ->assertJsonPath('data.rdv_a_valider', false)
            ->assertJsonPath('data.urgences_a_valider', true)
            ->assertJsonPath('data.acompte_centimes', null)
            ->assertJsonPath('data.acompte_delai_heures', 24);

        $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/services", [...$this->reglage([['service' => 'hospitalisation']], boxes: 6), 'rdv_sans_compte' => true, 'rdv_a_valider' => true, 'urgences_a_valider' => false, 'acompte_centimes' => 3000, 'acompte_delai_heures' => 48], $entetes)
            ->assertOk()->assertJsonPath('data.rdv_sans_compte', true)->assertJsonPath('data.rdv_a_valider', true)
            ->assertJsonPath('data.urgences_a_valider', false)->assertJsonPath('data.acompte_centimes', 3000)->assertJsonPath('data.acompte_delai_heures', 48);
        $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/services", $this->reglage([
            ['service' => 'hospitalisation'],
            ['service' => 'urgences', 'modalite' => 'permanence'],
        ], boxes: 6, affiche: false), $entetes)
            ->assertOk()->assertJsonPath('data.rdv_sans_compte', true);

        $this->getJson('/api/v1/hospitalisation/sejours', $entetes)->assertOk();
        $this->getJson('/api/v1/bloc/interventions', $entetes)->assertForbidden();
        $this->getJson('/api/v1/auth/moi', $entetes)->assertJsonPath('data.clinique.services', ['hospitalisation', 'urgences']);

        // Tout l'état d'un coup : ce qui n'est pas renvoyé est retiré
        $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/services", $this->reglage([['service' => 'chirurgie']], especes: []), $entetes)
            ->assertOk()
            ->assertJsonPath('data.services', [['service' => 'chirurgie', 'modalite' => null]])
            ->assertJsonPath('data.nombre_boxes', 0)
            ->assertJsonPath('data.especes', []);

        $this->getJson('/api/v1/hospitalisation/sejours', $entetes)->assertForbidden();
        $this->getJson('/api/v1/bloc/interventions', $entetes)->assertOk();
    }

    public function test_les_urgences_demandent_leur_modalite_et_l_hospitalisation_ses_boxes(): void
    {
        $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/services", $this->reglage([
            ['service' => 'urgences'],
            ['service' => 'hospitalisation'],
        ]), $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['services.0.modalite', 'nombre_boxes']);
    }

    public function test_l_hospitalisation_ne_se_coupe_pas_tant_qu_un_animal_y_sejourne(): void
    {
        $this->clinique->services()->create(['service' => 'hospitalisation']);
        $this->clinique->update(['nombre_boxes' => 8]);
        Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'box' => 5]);
        $entetes = $this->enTetes($this->jeton);

        $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/services", $this->reglage([]), $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('services');

        $this->putJson("/api/v1/organisation/cliniques/{$this->clinique->id}/services", $this->reglage([['service' => 'hospitalisation']], boxes: 4), $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('nombre_boxes');
    }

    public function test_une_clinique_d_une_autre_organisation_est_introuvable(): void
    {
        $ailleurs = Clinique::factory()->create();

        $this->putJson("/api/v1/organisation/cliniques/{$ailleurs->id}/services", $this->reglage([]), $this->enTetes($this->jeton))
            ->assertNotFound();
    }

    public function test_la_boutique_et_la_visio_publiques_suivent_les_services(): void
    {
        ['jeton' => $proprietaire] = $this->proprietaireAvecJeton();
        $avec = Clinique::factory()->create(['nom' => 'Avec boutique', 'rdv_a_valider' => true]);
        $sans = Clinique::factory()->sansServices()->create(['nom' => 'Sans rien']);
        $entetes = $this->enTetes($proprietaire);

        $noms = collect($this->getJson('/api/v1/espace/boutique', $entetes)->assertOk()->json('data'))->pluck('nom');
        $this->assertContains('Avec boutique', $noms);
        $this->assertNotContains('Sans rien', $noms);

        $demande = fn (Clinique $clinique) => [
            'clinique_id' => $clinique->id,
            'debut_souhaite' => now()->addDays(2)->setTime(14, 30)->toIso8601String(),
            'mode' => 'visio',
            'patient_name' => 'Nala',
            'espece' => 'CH',
            'motif' => 'Consultation',
        ];
        $this->postJson('/api/v1/espace/demandes', $demande($sans), $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->postJson('/api/v1/espace/demandes', $demande($avec), $entetes)->assertCreated();
    }
}
