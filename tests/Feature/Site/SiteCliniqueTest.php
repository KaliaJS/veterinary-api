<?php

namespace Tests\Feature\Site;

use App\Auth\RolesParDefaut;
use App\Models\Clinique;
use App\Models\HoraireOuverture;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Site web d'une clinique : édité depuis l'application par le domaine
 * organisation, servi sans compte une fois publié, horaires mis en
 * forme depuis les réglages de la clinique.
 */
class SiteCliniqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create(['nom' => 'Clinique des Deux Rives']);
        $this->jeton = $this->administrateur($this->clinique);
    }

    private function administrateur(Clinique $clinique): string
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($clinique);

        $employe->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $clinique->societe->organisation_id)
                ->where('nom', RolesParDefaut::ADMINISTRATEUR)
                ->value('id'),
        ]);

        return $jeton;
    }

    public function test_la_premiere_lecture_cree_un_site_non_publie_avec_contenu_de_depart(): void
    {
        $this->getJson('/api/v1/site', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.slug', 'clinique-des-deux-rives')
            ->assertJsonPath('data.est_publie', false)
            ->assertJsonPath('data.couleur', 'sauge')
            ->assertJsonPath('data.services.0.titre', 'Consultations')
            ->assertJsonPath('data.clinique.nom', 'Clinique des Deux Rives')
            ->assertJsonCount(1, 'data.equipe');

        $this->assertDatabaseHas('sites_cliniques', ['clinique_id' => $this->clinique->id]);
    }

    public function test_la_modification_remplace_les_blocs_envoyes(): void
    {
        $this->getJson('/api/v1/site', $this->enTetes($this->jeton));

        $this->patchJson('/api/v1/site', [
            'est_publie' => true,
            'couleur' => 'ocean',
            'accroche' => 'Des soins attentifs, au bord du lac.',
            'services' => [['titre' => 'Dentisterie', 'description' => 'Détartrage et soins dentaires.']],
            'actualites' => [
                ['titre' => 'Ancienne', 'texte' => null, 'date' => '2026-09-01'],
                ['titre' => 'Récente', 'texte' => 'Nouveaux horaires', 'date' => '2026-10-01'],
            ],
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.est_publie', true)
            ->assertJsonPath('data.couleur', 'ocean')
            ->assertJsonCount(1, 'data.services')
            ->assertJsonPath('data.actualites.0.titre', 'Récente');
    }

    public function test_le_slug_est_valide_et_unique_entre_cliniques(): void
    {
        $autre = Clinique::factory()->create(['nom' => 'Le Lac']);
        $this->getJson('/api/v1/site', $this->enTetes($this->administrateur($autre)));

        $this->patchJson('/api/v1/site', ['slug' => 'Mon Site !'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');

        $this->patchJson('/api/v1/site', ['slug' => 'le-lac'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');

        $this->patchJson('/api/v1/site', ['slug' => 'deux-rives'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.slug', 'deux-rives');
    }

    public function test_un_veterinaire_ne_peut_ni_lire_ni_modifier_le_site(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/site', $this->enTetes($jeton))->assertForbidden();
        $this->patchJson('/api/v1/site', ['est_publie' => true], $this->enTetes($jeton))->assertForbidden();
    }

    public function test_chaque_clinique_edite_son_propre_site(): void
    {
        $autre = Clinique::factory()->create(['nom' => 'Le Lac']);
        $jetonAutre = $this->administrateur($autre);

        $this->patchJson('/api/v1/site', ['accroche' => 'Deux Rives'], $this->enTetes($this->jeton))->assertOk();

        $this->getJson('/api/v1/site', $this->enTetes($jetonAutre))
            ->assertOk()
            ->assertJsonPath('data.slug', 'le-lac')
            ->assertJsonPath('data.accroche', null);
    }

    public function test_le_site_public_n_est_servi_qu_une_fois_publie(): void
    {
        $this->getJson('/api/v1/site', $this->enTetes($this->jeton));

        $this->getJson('/api/v1/public/sites/clinique-des-deux-rives')->assertNotFound();

        $this->patchJson('/api/v1/site', ['est_publie' => true, 'email' => 'accueil@deuxrives.ch'], $this->enTetes($this->jeton))->assertOk();

        $this->getJson('/api/v1/public/sites/clinique-des-deux-rives')
            ->assertOk()
            ->assertJsonPath('data.clinique.nom', 'Clinique des Deux Rives')
            ->assertJsonPath('data.clinique.email', 'accueil@deuxrives.ch')
            ->assertJsonCount(7, 'data.horaires.jours');
    }

    public function test_les_horaires_publics_suivent_les_reglages_de_la_clinique(): void
    {
        HoraireOuverture::withoutGlobalScopes()->create([...HoraireOuverture::DEFAUTS, 'clinique_id' => $this->clinique->id]);
        $this->patchJson('/api/v1/site', ['est_publie' => true], $this->enTetes($this->jeton));

        // Mercredi 10:00, heure de Zurich : ouvert jusqu'à la pause.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 10:00', 'Europe/Zurich'));

        $this->getJson('/api/v1/public/sites/clinique-des-deux-rives')
            ->assertOk()
            ->assertJsonPath('data.horaires.jours.0.jour', 'Lundi')
            ->assertJsonPath('data.horaires.jours.0.plages', ['08:00 – 12:30', '14:00 – 19:30'])
            ->assertJsonPath('data.horaires.jours.5.plages', ['08:00 – 12:30', '14:00 – 15:00'])
            ->assertJsonPath('data.horaires.jours.6.plages', [])
            ->assertJsonPath('data.horaires.jours.2.est_aujourdhui', true)
            ->assertJsonPath('data.horaires.est_ouvert', true)
            ->assertJsonPath('data.horaires.statut', 'Ouvert · jusqu’à 12:30');

        // Dimanche soir : fermé, réouverture le lundi.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 20:00', 'Europe/Zurich'));

        $this->getJson('/api/v1/public/sites/clinique-des-deux-rives')
            ->assertJsonPath('data.horaires.est_ouvert', false)
            ->assertJsonPath('data.horaires.statut', 'Fermé · ouvre demain à 08:00');

        CarbonImmutable::setTestNow();
    }

    public function test_l_annuaire_public_expose_le_site_publie(): void
    {
        $this->getJson('/api/v1/public/cliniques')->assertOk()->assertJsonPath('data.0.site_slug', null);

        $this->patchJson('/api/v1/site', ['est_publie' => true], $this->enTetes($this->jeton));

        $this->getJson('/api/v1/public/cliniques')->assertJsonPath('data.0.site_slug', 'clinique-des-deux-rives');
    }
}
