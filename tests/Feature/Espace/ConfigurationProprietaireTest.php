<?php

namespace Tests\Feature\Espace;

use App\Models\Client;
use App\Models\Clinique;
use App\Services\Comptes\SuppressionCompte;
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

        // Un mobile, puis les SMS : la ligne reste faite
        $this->putJson('/api/v1/espace/coordonnees', ['prenom' => 'Léa', 'nom' => 'Martin', 'tel' => '079 123 45 67'], $this->enTetes($jeton))->assertOk();
        $this->putJson('/api/v1/espace/preferences', ['rappels_sms' => true], $this->enTetes($jeton))->assertOk();
        $this->assertTrue(collect($this->etat($jeton)['etapes'])->firstWhere('cle', 'rappels')['fait']);

        // Le mobile remplacé par un fixe : les SMS ne partent plus, la ligne redevient à faire
        $this->putJson('/api/v1/espace/coordonnees', ['prenom' => 'Léa', 'nom' => 'Martin', 'tel' => '032 123 45 67'], $this->enTetes($jeton))->assertOk();
        $this->assertFalse(collect($this->etat($jeton)['etapes'])->firstWhere('cle', 'rappels')['fait']);
    }

    /** Il choisit le SMS « avec son mobile » : sans mobile (ou avec un fixe), les SMS ne s'activent pas, d'où qu'il les demande */
    public function test_les_sms_ne_s_activent_pas_sans_mobile(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->putJson('/api/v1/espace/preferences', ['rappels_email' => false, 'rappels_sms' => true], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('rappels_sms');
        $this->getJson('/api/v1/espace/preferences', $this->enTetes($jeton))->assertOk()
            ->assertJsonPath('data.rappels_sms', false)->assertJsonPath('data.rappels_email', true);

        $this->putJson('/api/v1/espace/coordonnees', ['prenom' => 'Léa', 'nom' => 'Martin', 'tel' => '032 123 45 67'], $this->enTetes($jeton))->assertOk();
        $this->putJson('/api/v1/espace/preferences', ['rappels_sms' => true], $this->enTetes($jeton))->assertUnprocessable();

        $this->putJson('/api/v1/espace/mobile', ['tel' => '079 123 45 67'], $this->enTetes($jeton))->assertOk();
        $this->putJson('/api/v1/espace/preferences', ['rappels_sms' => true], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.rappels_sms', true);
        // Couper les SMS reste toujours possible
        $this->putJson('/api/v1/espace/coordonnees', ['prenom' => 'Léa', 'nom' => 'Martin', 'tel' => null], $this->enTetes($jeton))->assertOk();
        $this->putJson('/api/v1/espace/preferences', ['rappels_sms' => false], $this->enTetes($jeton))->assertOk();
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

    /** Les coordonnées gardées sur le compte arrivent dans une fiche rattachée plus tard (le SMS lit la fiche) */
    public function test_les_coordonnees_du_compte_arrivent_dans_une_fiche_rattachee_plus_tard(): void
    {
        ['jeton' => $jeton, 'user' => $user] = $this->proprietaireAvecJeton(['email' => 'lea.martin@example.ch']);
        $this->putJson('/api/v1/espace/coordonnees', ['prenom' => 'Léa', 'nom' => 'Martin', 'tel' => '079 123 45 67', 'rue' => 'Rue du Lac 1', 'npa' => '2502', 'localite' => 'Bienne'], $this->enTetes($jeton))->assertOk();
        $clinique = Clinique::factory()->create();
        ['jeton' => $accueil] = $this->administrateurAvecJeton($clinique);
        $fiche = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'prenom' => 'Léa', 'nom' => 'Martin', 'tel' => null, 'rue' => null, 'npa' => null, 'localite' => null]);

        $this->postJson("/api/v1/clients/{$fiche->id}/compte", ['email' => 'lea.martin@example.ch'], $this->enTetes($accueil))->assertOk();

        $fiche->refresh();
        $this->assertSame([$user->id, '079 123 45 67', 'Rue du Lac 1', '2502', 'Bienne'], [$fiche->user_id, $fiche->tel, $fiche->rue, $fiche->npa, $fiche->localite]);
    }

    /** Le compte effacé ne garde pas ses coordonnées ; l'export les donne */
    public function test_les_coordonnees_du_compte_s_exportent_et_s_effacent(): void
    {
        ['jeton' => $jeton, 'user' => $user] = $this->proprietaireAvecJeton();
        $this->putJson('/api/v1/espace/coordonnees', ['prenom' => 'Léa', 'nom' => 'Martin', 'tel' => '079 123 45 67', 'rue' => 'Rue du Lac 1', 'npa' => '2502', 'localite' => 'Bienne'], $this->enTetes($jeton))->assertOk();

        $export = $this->getJson('/api/v1/espace/export', $this->enTetes($jeton))->assertOk()->json('data.compte');
        $this->assertSame(['079 123 45 67', 'Rue du Lac 1', '2502', 'Bienne'], [$export['tel'] ?? null, $export['rue'] ?? null, $export['npa'] ?? null, $export['localite'] ?? null]);

        // Effacé (demande de 30 jours) : un propriétaire disparaît avec ses coordonnées ;
        // la trace d'un ancien membre les perd aussi (SuppressionCompteTest)
        $user->refresh()->forceFill(['suppression_demandee_le' => now()->subDays(31)])->save();
        app(SuppressionCompte::class)->effacer($user);
        $this->assertDatabaseMissing('users', ['id' => $user->id, 'tel' => '079 123 45 67']);
    }

    /** Inscrit à l'instant (nom provisoire) : son mobile s'enregistre seul, sans prénom ni nom (vérification du 28.10.2026, défaut grave) */
    public function test_un_compte_neuf_enregistre_son_mobile_sans_prenom_ni_nom(): void
    {
        ['jeton' => $jeton, 'user' => $user] = $this->proprietaireAvecJeton(['name' => 'lea.martin']);
        $clinique = Clinique::factory()->create();
        Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'user_id' => $user->id, 'tel' => null]);

        $this->putJson('/api/v1/espace/mobile', ['tel' => '079 123 45 67'], $this->enTetes($jeton))->assertOk()->assertJsonPath('data.tel', '079 123 45 67');

        $this->assertSame('079 123 45 67', $user->refresh()->tel);
        $this->assertSame('079 123 45 67', Client::withoutGlobalScopes()->where('user_id', $user->id)->value('tel'));
    }

    /** Un fixe n'est pas un mobile : refusé, rien ne change */
    public function test_un_fixe_n_est_pas_enregistre_comme_mobile(): void
    {
        ['jeton' => $jeton, 'user' => $user] = $this->proprietaireAvecJeton();

        $this->putJson('/api/v1/espace/mobile', ['tel' => '032 123 45 67'], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('tel');

        $this->assertNull($user->refresh()->tel);
    }
}
