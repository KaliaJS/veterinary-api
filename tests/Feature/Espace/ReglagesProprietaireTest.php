<?php

namespace Tests\Feature\Espace;

use App\Auth\EmetteurJetons;
use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Patient;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Espace propriétaire — réglages : préférences aux défauts du proto,
 * appareils connectés, notifications dérivées avec état lu, export.
 */
class ReglagesProprietaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_les_preferences_ont_les_defauts_du_proto_et_se_modifient(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->getJson('/api/v1/espace/preferences', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.rappels_email', true)
            ->assertJsonPath('data.rappels_sms', false)
            ->assertJsonPath('data.reponses_clinique', true)
            ->assertJsonPath('data.nouveautes_boutique', false)
            ->assertJsonPath('data.statistiques_anonymes', true)
            ->assertJsonPath('data.langue', 'fr');

        $this->putJson('/api/v1/espace/preferences', ['rappels_sms' => true, 'langue' => 'de'], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.rappels_sms', true)
            ->assertJsonPath('data.rappels_email', true)
            ->assertJsonPath('data.langue', 'de');

        $this->putJson('/api/v1/espace/preferences', ['langue' => 'xx'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('langue');
    }

    public function test_les_appareils_listent_les_jetons_et_les_autres_se_ferment(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        app(EmetteurJetons::class)->emettreSansClinique($user, 'iphone');
        app(EmetteurJetons::class)->emettreSansClinique($user, 'ipad');

        $this->getJson('/api/v1/espace/appareils', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->postJson('/api/v1/espace/appareils/deconnecter-autres', [], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.fermes', 2);

        $this->getJson('/api/v1/espace/appareils', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.est_courant', true);
    }

    public function test_les_notifications_derivent_de_l_accueil_et_se_marquent_lues(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        $max = Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Max']);
        $rage = Vaccination::factory()->create(['patient_id' => $max->id, 'nom' => 'Rage', 'injectee_le' => today()->subYear(), 'echeance_le' => today()->subDays(8)]);
        $devis = Devis::factory()->create(['patient_id' => $max->id, 'organisation_id' => $fiche->organisation_id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail, 'signe_le' => null, 'motif' => 'Détartrage']);

        $this->getJson('/api/v1/espace/notifications', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(2, 'data.notifications')
            ->assertJsonPath('data.non_lues', 2)
            ->assertJsonPath('data.notifications.0.cle', 'vaccin:'.$rage->id)
            ->assertJsonPath('data.notifications.0.titre', 'Vaccin Rage de Max en retard')
            ->assertJsonPath('data.notifications.0.cible', 'rendez-vous')
            ->assertJsonPath('data.notifications.1.cle', 'devis:'.$devis->id)
            ->assertJsonPath('data.notifications.1.lue', false);

        $this->postJson('/api/v1/espace/notifications/lues', ['cles' => ['devis:'.$devis->id]], $this->enTetes($jeton))->assertOk();

        $this->getJson('/api/v1/espace/notifications', $this->enTetes($jeton))
            ->assertJsonPath('data.non_lues', 1)
            ->assertJsonPath('data.notifications.1.lue', true);
    }

    public function test_l_export_rassemble_les_donnees_du_compte(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Anna Meyer']);
        $fiche = Client::factory()->create(['user_id' => $user->id]);
        Patient::factory()->create(['client_id' => $fiche->id, 'nom' => 'Max']);

        $this->getJson('/api/v1/espace/export', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.compte.nom', 'Anna Meyer')
            ->assertJsonCount(1, 'data.fiches')
            ->assertJsonPath('data.animaux.0.nom', 'Max')
            ->assertJsonStructure(['data' => ['exporte_le', 'compte', 'fiches', 'animaux', 'rendez_vous', 'commandes', 'messages', 'preferences']]);
    }
}
