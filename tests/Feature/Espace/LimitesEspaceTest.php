<?php

namespace Tests\Feature\Espace;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Espace propriétaire (23.10.2026) : les gestes qui écrivent chez une
 * clinique sont limités par compte (un script ne remplit pas l'agenda
 * d'une clinique, ne balaie pas les numéros de puce).
 */
class LimitesEspaceTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_la_reclamation_par_puce_est_limitee_a_cinq_par_heure(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/espace/animaux/reclamer', ['puce' => '75609800000000'.$i, 'nom_proprietaire' => 'Pérez'], $this->enTetes($jeton))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['puce' => 'Aucun animal ne correspond à ce numéro de puce et à ce nom de famille. Vérifiez-les, ou appelez la clinique qui le suit.']);
        }

        $this->postJson('/api/v1/espace/animaux/reclamer', ['puce' => '756098000000009', 'nom_proprietaire' => 'Pérez'], $this->enTetes($jeton))
            ->assertTooManyRequests();
    }

    public function test_les_demandes_de_rendez_vous_sont_limitees_a_dix_par_heure(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/espace/demandes', [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('clinique_id');
        }

        $this->postJson('/api/v1/espace/demandes', [], $this->enTetes($jeton))->assertTooManyRequests();
    }

    public function test_un_autre_compte_n_est_pas_touche(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        ['jeton' => $autre] = $this->proprietaireAvecJeton();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/espace/demandes', [], $this->enTetes($jeton));
        }

        $this->postJson('/api/v1/espace/demandes', [], $this->enTetes($autre))->assertUnprocessable()->assertJsonValidationErrors('clinique_id');
    }
}
