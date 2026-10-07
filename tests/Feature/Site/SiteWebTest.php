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
 * Site web d'une organisation : édité depuis l'application par le
 * domaine organisation, servi sans compte une fois publié, avec toutes
 * les cliniques de l'organisation et leurs horaires mis en forme.
 */
class SiteWebTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        $this->clinique->societe->organisation->update(['nom' => 'Deux Rives Vétérinaires']);
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

    private function publier(): void
    {
        $this->patchJson('/api/v1/site', ['est_publie' => true], $this->enTetes($this->jeton))->assertOk();
    }

    public function test_la_premiere_lecture_cree_un_site_non_publie_avec_contenu_de_depart(): void
    {
        $this->getJson('/api/v1/site', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.slug', 'deux-rives-veterinaires')
            ->assertJsonPath('data.est_publie', false)
            ->assertJsonPath('data.couleur', 'sauge')
            ->assertJsonPath('data.services.0.titre', 'Consultations')
            ->assertJsonPath('data.organisation.nom', 'Deux Rives Vétérinaires')
            ->assertJsonPath('data.cliniques.0.nom', 'Deux Rives')
            ->assertJsonCount(1, 'data.equipe')
            ->assertJsonPath('data.equipe.0.clinique', null);

        $this->assertDatabaseHas('sites_web', ['organisation_id' => $this->clinique->societe->organisation_id]);
    }

    public function test_la_modification_remplace_les_blocs_envoyes(): void
    {
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

    public function test_le_slug_est_valide_et_unique_entre_organisations(): void
    {
        $autre = Clinique::factory()->create(['nom' => 'Le Lac']);
        $autre->societe->organisation->update(['nom' => 'Le Lac']);
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

    public function test_les_cliniques_d_une_organisation_partagent_un_seul_site(): void
    {
        $soeur = Clinique::factory()->create(['nom' => 'Le Lac', 'societe_id' => $this->clinique->societe_id]);
        $jetonSoeur = $this->administrateur($soeur);

        $this->patchJson('/api/v1/site', ['accroche' => 'Deux cliniques, une équipe'], $this->enTetes($this->jeton))->assertOk();

        $this->getJson('/api/v1/site', $this->enTetes($jetonSoeur))
            ->assertOk()
            ->assertJsonPath('data.slug', 'deux-rives-veterinaires')
            ->assertJsonPath('data.accroche', 'Deux cliniques, une équipe')
            ->assertJsonCount(2, 'data.cliniques')
            // Aperçu de l'éditeur : horaires de chaque clinique, sans rien écrire pour la sœur.
            ->assertJsonCount(7, 'data.cliniques.1.horaires.jours')
            ->assertJsonPath('data.cliniques.1.horaires.jours.6.jour', 'Dimanche');

        $this->assertDatabaseCount('sites_web', 1);
    }

    public function test_une_autre_organisation_a_son_propre_site(): void
    {
        $autre = Clinique::factory()->create(['nom' => 'Le Lac']);
        $autre->societe->organisation->update(['nom' => 'Le Lac']);

        $this->patchJson('/api/v1/site', ['accroche' => 'Deux Rives'], $this->enTetes($this->jeton))->assertOk();

        $this->getJson('/api/v1/site', $this->enTetes($this->administrateur($autre)))
            ->assertOk()
            ->assertJsonPath('data.slug', 'le-lac')
            ->assertJsonPath('data.accroche', null);
    }

    public function test_le_site_public_n_est_servi_qu_une_fois_publie(): void
    {
        $this->getJson('/api/v1/site', $this->enTetes($this->jeton));

        $this->getJson('/api/v1/public/sites/deux-rives-veterinaires')->assertNotFound();

        $this->patchJson('/api/v1/site', ['est_publie' => true, 'email' => 'accueil@deuxrives.ch'], $this->enTetes($this->jeton))->assertOk();

        $this->getJson('/api/v1/public/sites/deux-rives-veterinaires')
            ->assertOk()
            ->assertJsonPath('data.organisation.nom', 'Deux Rives Vétérinaires')
            ->assertJsonPath('data.email', 'accueil@deuxrives.ch')
            ->assertJsonPath('data.cliniques.0.nom', 'Deux Rives')
            ->assertJsonCount(7, 'data.cliniques.0.horaires.jours');
    }

    public function test_le_site_public_presente_chaque_clinique_avec_ses_horaires(): void
    {
        $soeur = Clinique::factory()->create(['nom' => 'Le Lac', 'societe_id' => $this->clinique->societe_id]);
        HoraireOuverture::withoutGlobalScopes()->create([...HoraireOuverture::DEFAUTS, 'clinique_id' => $this->clinique->id]);
        HoraireOuverture::withoutGlobalScopes()->create([...HoraireOuverture::DEFAUTS, 'clinique_id' => $soeur->id, 'samedi_ferme' => true, 'pause_active' => false]);
        $this->publier();

        // Mercredi 10:00, heure de Zurich.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 10:00', 'Europe/Zurich'));

        $this->getJson('/api/v1/public/sites/deux-rives-veterinaires')
            ->assertOk()
            ->assertJsonCount(2, 'data.cliniques')
            ->assertJsonPath('data.cliniques.0.horaires.jours.0.jour', 'Lundi')
            ->assertJsonPath('data.cliniques.0.horaires.jours.0.plages', ['08:00 – 12:30', '14:00 – 19:30'])
            ->assertJsonPath('data.cliniques.0.horaires.jours.5.plages', ['08:00 – 12:30', '14:00 – 15:00'])
            ->assertJsonPath('data.cliniques.0.horaires.jours.6.plages', [])
            ->assertJsonPath('data.cliniques.0.horaires.jours.2.est_aujourdhui', true)
            ->assertJsonPath('data.cliniques.0.horaires.statut', 'Ouvert · jusqu’à 12:30')
            ->assertJsonPath('data.cliniques.1.nom', 'Le Lac')
            ->assertJsonPath('data.cliniques.1.horaires.jours.0.plages', ['08:00 – 19:30'])
            ->assertJsonPath('data.cliniques.1.horaires.jours.5.plages', [])
            ->assertJsonPath('data.cliniques.1.horaires.statut', 'Ouvert · jusqu’à 19:30');

        // Dimanche soir : fermé, réouverture le lundi.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 20:00', 'Europe/Zurich'));

        $this->getJson('/api/v1/public/sites/deux-rives-veterinaires')
            ->assertJsonPath('data.cliniques.0.horaires.est_ouvert', false)
            ->assertJsonPath('data.cliniques.0.horaires.statut', 'Fermé · ouvre demain à 08:00');

        CarbonImmutable::setTestNow();
    }

    public function test_l_annuaire_public_expose_le_site_de_l_organisation_sur_chaque_clinique(): void
    {
        Clinique::factory()->create(['nom' => 'Le Lac', 'societe_id' => $this->clinique->societe_id]);

        $this->getJson('/api/v1/public/cliniques')->assertOk()->assertJsonPath('data.0.site_slug', null);

        $this->publier();

        $this->getJson('/api/v1/public/cliniques')
            ->assertJsonPath('data.0.site_slug', 'deux-rives-veterinaires')
            ->assertJsonPath('data.1.site_slug', 'deux-rives-veterinaires');
    }
}
