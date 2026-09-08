<?php

namespace Tests\Feature\Espace;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\MessageClientele;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Espace propriétaire — messages (un fil par clinique, lecture qui
 * marque lu, envoi) et coordonnées (lues sur la fiche, écrites sur le
 * compte et toutes les fiches).
 */
class MessagesCoordonneesProprietaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_le_fil_par_clinique_se_lit_et_s_ecrit(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        Client::factory()->create(['user_id' => $user->id, 'organisation_id' => $clinique->societe->organisation_id]);
        $veterinaire = Employe::factory()->create(['clinique_id' => $clinique->id]);
        MessageClientele::create(['clinique_id' => $clinique->id, 'user_id' => $user->id, 'employe_id' => $veterinaire->id, 'auteur' => 'clinique', 'texte' => 'Comment va Paco ?']);
        Clinique::factory()->create(['nom' => 'Ailleurs']); // pas client

        // Non lu : visible à l'accueil et sur la liste des fils.
        $this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))
            ->assertJsonPath('data.messages_non_lus', 1)
            ->assertJsonPath('data.a_faire.0.type', 'message');

        $this->getJson('/api/v1/espace/messages', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data.cliniques')
            ->assertJsonPath('data.cliniques.0.nom', 'Deux Rives')
            ->assertJsonPath('data.cliniques.0.non_lus', 1)
            ->assertJsonPath('data.clinique_id', $clinique->id)
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.de_moi', false)
            ->assertJsonPath('data.messages.0.signature', $veterinaire->nom);

        // Lu à l'ouverture.
        $this->assertNotNull(MessageClientele::first()->lu_le);
        $this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))->assertJsonPath('data.messages_non_lus', 0);

        $this->postJson('/api/v1/espace/messages', ['clinique_id' => $clinique->id, 'texte' => 'Il remange normalement, merci !'], $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.de_moi', true)
            ->assertJsonPath('data.texte', 'Il remange normalement, merci !');

        $this->getJson("/api/v1/espace/messages?clinique_id={$clinique->id}", $this->enTetes($jeton))
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.1.auteur', 'proprietaire');

        // Une clinique dont on n'est pas client : refusée.
        $autre = Clinique::factory()->create();
        $this->postJson('/api/v1/espace/messages', ['clinique_id' => $autre->id, 'texte' => 'Bonjour'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('clinique_id');
    }

    public function test_sans_fiche_aucun_fil_n_est_disponible(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        Clinique::factory()->create();

        $this->getJson('/api/v1/espace/messages', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.cliniques', [])
            ->assertJsonPath('data.clinique_id', null)
            ->assertJsonPath('data.messages', []);
    }

    public function test_les_coordonnees_se_lisent_sur_la_fiche_et_s_ecrivent_partout(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'inscription.test']);
        $ficheA = Client::factory()->create(['user_id' => $user->id, 'prenom' => 'Anna', 'nom' => 'Meyer', 'tel' => '079 512 33 48', 'rue' => 'Avenue de Cour 24', 'npa' => '1007', 'localite' => 'Lausanne']);
        $ficheB = Client::factory()->create(['user_id' => $user->id, 'prenom' => 'Anna', 'nom' => 'Meyer', 'tel' => null]);

        $this->getJson('/api/v1/espace/coordonnees', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.prenom', 'Anna')
            ->assertJsonPath('data.tel', '079 512 33 48')
            ->assertJsonPath('data.npa', '1007')
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.nombre_fiches', 2);

        $this->putJson('/api/v1/espace/coordonnees', ['prenom' => 'Anna', 'nom' => 'Meyer-Roux', 'tel' => '078 000 00 00', 'rue' => 'Rue Centrale 12', 'npa' => '1003', 'localite' => 'Lausanne'], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.nom', 'Meyer-Roux')
            ->assertJsonPath('data.tel', '078 000 00 00');

        $this->assertSame('Anna Meyer-Roux', $user->fresh()->name);
        $this->assertSame('078 000 00 00', $ficheA->fresh()->tel);
        $this->assertSame('078 000 00 00', $ficheB->fresh()->tel);
        $this->assertSame('1003', $ficheB->fresh()->npa);

        $this->putJson('/api/v1/espace/coordonnees', ['prenom' => '', 'nom' => 'Meyer'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('prenom');
        $this->putJson('/api/v1/espace/coordonnees', ['prenom' => 'Anna', 'nom' => 'Meyer', 'npa' => '12'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('npa');
    }

    public function test_sans_fiche_les_coordonnees_viennent_du_compte(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Anna Meyer']);

        $this->getJson('/api/v1/espace/coordonnees', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.prenom', 'Anna')
            ->assertJsonPath('data.nom', 'Meyer')
            ->assertJsonPath('data.nombre_fiches', 0);
    }
}
