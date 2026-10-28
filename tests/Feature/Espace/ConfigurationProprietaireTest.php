<?php

namespace Tests\Feature\Espace;

use App\Models\Client;
use App\Models\Clinique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * « Terminer la configuration » du propriétaire (décisions de Jim,
 * 28.10.2026) : deux lignes, « Vos rappels » et « Vos coordonnées » ; la
 * fenêtre s'ouvre d'elle-même au premier accès, puis reste dans le menu
 * jusqu'à ce que tout soit fait ou masqué.
 */
class ConfigurationProprietaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private function etat(string $jeton): array
    {
        return $this->getJson('/api/v1/espace/configuration', $this->enTetes($jeton))->assertOk()->json('data');
    }

    public function test_un_nouveau_compte_a_deux_lignes_a_faire_et_la_fenetre_s_ouvre_d_elle_meme(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $etat = $this->etat($jeton);

        $this->assertSame([['cle' => 'rappels', 'fait' => false], ['cle' => 'coordonnees', 'fait' => false]], $etat['etapes']);
        $this->assertTrue($etat['ouvrir']);
        $this->assertFalse($etat['masquee']);
    }

    public function test_vue_une_fois_elle_ne_s_ouvre_plus_d_elle_meme(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->putJson('/api/v1/espace/configuration', ['vue' => true], $this->enTetes($jeton))->assertOk();

        $this->assertFalse($this->etat($jeton)['ouvrir']);
    }

    public function test_choisir_ses_rappels_fait_la_ligne_et_le_sms_demande_un_mobile(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->putJson('/api/v1/espace/preferences', ['rappels_email' => true, 'rappels_sms' => false], $this->enTetes($jeton))->assertOk();
        $this->assertTrue(collect($this->etat($jeton)['etapes'])->firstWhere('cle', 'rappels')['fait']);

        // SMS choisi sans mobile : pas encore fait
        $this->putJson('/api/v1/espace/preferences', ['rappels_sms' => true], $this->enTetes($jeton))->assertOk();
        $this->assertFalse(collect($this->etat($jeton)['etapes'])->firstWhere('cle', 'rappels')['fait']);

        $this->putJson('/api/v1/espace/coordonnees', ['prenom' => 'Léa', 'nom' => 'Martin', 'tel' => '079 123 45 67'], $this->enTetes($jeton))->assertOk();
        $this->assertTrue(collect($this->etat($jeton)['etapes'])->firstWhere('cle', 'rappels')['fait']);
    }

    public function test_les_coordonnees_se_gardent_meme_sans_fiche_de_clinique_et_font_la_ligne(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->putJson('/api/v1/espace/coordonnees', ['prenom' => 'Léa', 'nom' => 'Martin', 'tel' => '079 123 45 67', 'rue' => 'Rue du Lac 1', 'npa' => '2502', 'localite' => 'Bienne'], $this->enTetes($jeton))
            ->assertOk()->assertJsonPath('data.tel', '079 123 45 67')->assertJsonPath('data.localite', 'Bienne');

        $this->getJson('/api/v1/espace/coordonnees', $this->enTetes($jeton))->assertOk()->assertJsonPath('data.rue', 'Rue du Lac 1');
        $this->assertTrue(collect($this->etat($jeton)['etapes'])->firstWhere('cle', 'coordonnees')['fait']);
    }

    public function test_une_fiche_de_clinique_complete_fait_la_ligne_des_coordonnees(): void
    {
        ['jeton' => $jeton, 'user' => $user] = $this->proprietaireAvecJeton();
        $clinique = Clinique::factory()->create();
        Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'user_id' => $user->id, 'tel' => '079 123 45 67', 'rue' => 'Rue du Lac 1', 'npa' => '2502', 'localite' => 'Bienne']);

        $this->assertTrue(collect($this->etat($jeton)['etapes'])->firstWhere('cle', 'coordonnees')['fait']);
    }

    public function test_masquee_elle_quitte_le_menu_et_ne_s_ouvre_plus(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->putJson('/api/v1/espace/configuration', ['masquee' => true], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.masquee', true);

        $this->assertFalse($this->etat($jeton)['ouvrir']);
    }

    public function test_tout_fait_elle_ne_s_ouvre_pas(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->putJson('/api/v1/espace/preferences', ['rappels_email' => true], $this->enTetes($jeton))->assertOk();
        $this->putJson('/api/v1/espace/coordonnees', ['prenom' => 'Léa', 'nom' => 'Martin', 'tel' => '079 123 45 67', 'rue' => 'Rue du Lac 1', 'npa' => '2502', 'localite' => 'Bienne'], $this->enTetes($jeton))->assertOk();

        $this->assertFalse($this->etat($jeton)['ouvrir']);
    }
}
