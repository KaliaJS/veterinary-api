<?php

namespace Tests\Feature\Patients;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Révoquer le compte espace d'un client (23.10.2026) : le compte n'est
 * supprimé que s'il ne servait qu'à cette fiche ; client d'une autre
 * organisation, il reste à son titulaire.
 */
class CompteClientRevocationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_un_compte_qui_ne_sert_qu_a_cette_fiche_est_supprime(): void
    {
        $clinique = Clinique::factory()->create();
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $user = User::factory()->create();
        $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'user_id' => $user->id]);

        $this->deleteJson("/api/v1/clients/{$client->id}/compte", [], $this->enTetes($jeton))->assertNoContent();

        $this->assertNull(User::query()->find($user->id));
    }

    public function test_un_compte_client_d_une_autre_organisation_reste_a_son_titulaire(): void
    {
        $clinique = Clinique::factory()->create();
        $autre = Clinique::factory()->create();
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $user = User::factory()->create();
        $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'user_id' => $user->id]);
        Client::factory()->create(['organisation_id' => $autre->societe->organisation_id, 'user_id' => $user->id]);

        $this->deleteJson("/api/v1/clients/{$client->id}/compte", [], $this->enTetes($jeton))->assertNoContent();

        $this->assertNotNull(User::query()->find($user->id));
        $this->assertNull($client->fresh()->user_id);
    }

    /** Plus par l'App Store, modérateur, identité payée, GLN : le compte vit par lui-même et reste. */
    public function test_un_compte_qui_vit_par_lui_meme_reste_a_son_titulaire(): void
    {
        $clinique = Clinique::factory()->create();
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);

        foreach ([['apple_abonnement_id' => '2000000001'], ['est_moderateur' => true], ['identite_payee_le' => now()], ['gln' => '7601000999993']] as $attributs) {
            $user = User::factory()->create();
            $user->forceFill($attributs)->save();
            $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'user_id' => $user->id]);

            $this->deleteJson("/api/v1/clients/{$client->id}/compte", [], $this->enTetes($jeton))->assertNoContent();

            $this->assertNotNull(User::query()->find($user->id), (string) array_key_first($attributs));
            $this->assertNull($client->fresh()->user_id);
        }
    }
}
