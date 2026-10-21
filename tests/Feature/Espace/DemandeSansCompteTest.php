<?php

namespace Tests\Feature\Espace;

use App\Models\Clinique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Rendez-vous sans compte : seulement chez une clinique qui l'accepte
 * (non par défaut), avec de quoi répondre au propriétaire ; la clinique
 * la voit dans sa file comme les autres demandes.
 */
class DemandeSansCompteTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function demande(Clinique $clinique, array $champs = []): array
    {
        return [
            'clinique_id' => $clinique->id,
            'debut_souhaite' => now()->addDays(2)->setTime(10, 0)->toIso8601String(),
            'mode' => 'place',
            'patient_name' => 'Pacha',
            'espece' => 'CT',
            'motif' => 'Consultation',
            'nom_proprietaire' => 'Léa Rochat',
            'email' => 'Lea.Rochat@example.ch',
            'telephone' => '+41 79 123 45 67',
            ...$champs,
        ];
    }

    public function test_une_clinique_demande_un_compte_par_defaut(): void
    {
        $clinique = Clinique::factory()->create();

        $this->assertFalse($clinique->fresh()->rdv_sans_compte);
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique))
            ->assertUnprocessable()->assertJsonValidationErrors('clinique_id');
        $this->assertDatabaseCount('demandes_rendez_vous', 0);
    }

    public function test_la_clinique_qui_l_accepte_recoit_la_demande_avec_les_coordonnees(): void
    {
        // Elle valide chaque rendez-vous : la demande reste dans sa file
        $clinique = Clinique::factory()->create(['rdv_sans_compte' => true, 'rdv_a_valider' => true]);

        $this->getJson('/api/v1/public/cliniques')->assertOk()
            ->assertJsonPath('data.0.rdv_sans_compte', true);

        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['urgence' => true, 'symptomes' => 'Boite depuis hier']))
            ->assertCreated()
            ->assertJsonPath('data.patient_name', 'Pacha')
            ->assertJsonPath('data.urgence', true);

        $this->assertDatabaseHas('demandes_rendez_vous', [
            'clinique_id' => $clinique->id,
            'client_id' => null,
            'patient_id' => null,
            'owner_name' => 'Léa Rochat',
            'email' => 'lea.rochat@example.ch',
            'telephone' => '+41 79 123 45 67',
            'urgence' => true,
            'symptomes' => 'Boite depuis hier',
        ]);

        // La clinique la voit dans sa file, avec de quoi répondre
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $this->getJson('/api/v1/demandes', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.0.email', 'lea.rochat@example.ch')
            ->assertJsonPath('data.0.telephone', '+41 79 123 45 67')
            ->assertJsonPath('data.0.urgence', true)
            ->assertJsonPath('data.0.symptomes', 'Boite depuis hier');
    }

    public function test_sans_compte_il_faut_de_quoi_repondre(): void
    {
        $clinique = Clinique::factory()->create(['rdv_sans_compte' => true]);

        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['email' => '', 'telephone' => '12', 'nom_proprietaire' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'telephone', 'nom_proprietaire']);
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['urgence' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('symptomes');
    }
}
