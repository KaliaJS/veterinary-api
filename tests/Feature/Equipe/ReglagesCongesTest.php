<?php

namespace Tests\Feature\Equipe;

use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\User;
use App\Services\Equipe\Embauche;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les règles des congés de l'organisation (Jim, 11.11.2026, É10 et É11) :
 * le droit par défaut, le seuil et la date des jours reportés, le rappel
 * des deux semaines de suite, et les congés posés sans validation
 * (désactivé de base). Les changer demande le droit Ressources humaines.
 */
class ReglagesCongesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_les_regles_par_defaut_puis_changees(): void
    {
        $clinique = Clinique::factory()->create();
        ['jeton' => $jeton] = $this->administrateurAvecJeton($clinique);

        $this->getJson('/api/v1/equipe/conges/reglages', $this->enTetes($jeton))
            ->assertOk()
            ->assertExactJson(['data' => ['droit_defaut' => 20, 'seuil_report' => 5, 'planifier_avant' => '03-31', 'rappel_deux_semaines' => true, 'sans_validation' => false]]);

        $this->putJson('/api/v1/equipe/conges/reglages', ['droit_defaut' => 25, 'planifier_avant' => '06-30', 'sans_validation' => true], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.droit_defaut', 25)
            ->assertJsonPath('data.planifier_avant', '06-30')
            ->assertJsonPath('data.sans_validation', true)
            ->assertJsonPath('data.seuil_report', 5);

        $this->putJson('/api/v1/equipe/conges/reglages', ['droit_defaut' => 12], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('droit_defaut');
        $this->putJson('/api/v1/equipe/conges/reglages', ['planifier_avant' => '02-30'], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('planifier_avant');
    }

    public function test_sans_le_droit_rh_les_regles_se_lisent_sans_se_changer(): void
    {
        $clinique = Clinique::factory()->create();
        ['employe' => $employe, 'jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        DerogationPermission::create(['employe_id' => $employe->id, 'domaine' => DomainePermission::Rh, 'est_accordee' => false]);

        $this->getJson('/api/v1/equipe/conges/reglages', $this->enTetes($jeton))->assertOk();
        $this->putJson('/api/v1/equipe/conges/reglages', ['sans_validation' => true], $this->enTetes($jeton))->assertForbidden();
    }

    public function test_l_embauche_prend_le_droit_par_defaut_de_l_organisation(): void
    {
        $clinique = Clinique::factory()->create();
        $organisation = $clinique->societe->organisation;
        $organisation->forceFill(['conges_droit_defaut' => 23])->save();

        $employe = app(Embauche::class)->embaucher($organisation, User::factory()->create(), ['poste' => 'ASV', 'contrat_statut' => 'signe', 'cliniques' => [$clinique->id]]);

        $this->assertSame(23, $employe->conges_droit);
    }
}
