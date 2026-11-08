<?php

namespace Tests\Feature\Patients;

use App\Auth\ContexteClinique;
use App\Enums\Espece;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\NeuroExam;
use App\Models\Organisation;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Schéma neurologique (B, choix de Jim, 08.11.2026) : un examen par geste,
 * gardé au dossier de l'animal. Par patte : motricité, proprioception,
 * réflexes (retrait ; patellaire aux postérieurs), douleur profonde ; le
 * dos : régions douloureuses à la palpation, réflexe cutané du tronc et sa
 * vertèbre de coupure ; l'état de conscience, les nerfs crâniens à gauche
 * et à droite, un score de démarche. Les signes seulement : l'API ne
 * propose aucune localisation. Un examen se modifie le jour même, par
 * l'organisation qui l'a fait ; ensuite il se relit seulement.
 */
class NeuroExamTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $chien;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->chien = Patient::factory()->for($client)->create(['espece' => Espece::Chien]);

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function uri(?int $examen = null): string
    {
        return "/api/v1/patients/{$this->chien->id}/neuro-exams".($examen ? "/{$examen}" : '');
    }

    /** @return array<string, mixed> */
    private function examenComplet(): array
    {
        return [
            'mental_status' => 'obtunded',
            'gait_score' => 2,
            'limbs' => [
                'left_front' => ['motor' => 'normal', 'proprioception' => 'normal', 'withdrawal' => 'normal', 'deep_pain' => null],
                'right_front' => ['motor' => 'normal', 'proprioception' => 'normal', 'withdrawal' => 'normal'],
                'left_hind' => ['motor' => 'non_ambulatory_paresis', 'proprioception' => 'absent', 'withdrawal' => 'normal', 'patellar' => 'increased', 'deep_pain' => 'present'],
                'right_hind' => ['motor' => 'plegia', 'proprioception' => 'absent', 'withdrawal' => 'decreased', 'patellar' => 'increased', 'deep_pain' => 'absent'],
            ],
            'spine' => ['painful_regions' => ['lumbar'], 'cutaneous_trunci' => 'cutoff', 'cutoff_at' => 'L1'],
            'cranial_nerves' => [
                'menace' => ['left' => 'normal', 'right' => 'decreased'],
                'pupillary_light' => ['left' => 'normal', 'right' => 'normal'],
            ],
            'note' => 'Apparu hier soir après un saut.',
        ];
    }

    public function test_un_examen_complet_est_garde_au_dossier_et_relu(): void
    {
        $this->travelTo(now()->setTime(10, 0));

        $id = $this->postJson($this->uri(), $this->examenComplet(), $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.day', aujourdhui()->toDateString())
            ->assertJsonPath('data.is_editable', true)
            ->json('data.id');

        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.mental_status', 'obtunded')
            ->assertJsonPath('data.0.gait_score', 2)
            ->assertJsonPath('data.0.limbs.right_hind.motor', 'plegia')
            ->assertJsonPath('data.0.limbs.right_hind.deep_pain', 'absent')
            ->assertJsonPath('data.0.limbs.left_hind.patellar', 'increased')
            ->assertJsonPath('data.0.spine.painful_regions', ['lumbar'])
            ->assertJsonPath('data.0.spine.cutaneous_trunci', 'cutoff')
            ->assertJsonPath('data.0.spine.cutoff_at', 'L1')
            ->assertJsonPath('data.0.cranial_nerves.menace.right', 'decreased')
            ->assertJsonPath('data.0.note', 'Apparu hier soir après un saut.');
    }

    public function test_la_reponse_a_toujours_toutes_les_cles_meme_non_examinees(): void
    {
        $this->postJson($this->uri(), [], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.mental_status', null)
            ->assertJsonPath('data.gait_score', null)
            ->assertJsonPath('data.limbs.left_front.motor', null)
            ->assertJsonPath('data.limbs.left_front.patellar', null)
            ->assertJsonPath('data.limbs.right_hind.deep_pain', null)
            ->assertJsonPath('data.spine.painful_regions', [])
            ->assertJsonPath('data.spine.cutaneous_trunci', null)
            ->assertJsonPath('data.cranial_nerves.physiological_nystagmus.left', null)
            ->assertJsonPath('data.by_name', fn ($nom) => is_string($nom) && $nom !== '');
    }

    public function test_les_valeurs_inconnues_sont_refusees(): void
    {
        $cas = [
            'limbs.left_front.motor' => ['limbs' => ['left_front' => ['motor' => 'boiterie']]],
            'limbs.left_front.patellar' => ['limbs' => ['left_front' => ['patellar' => 'normal']]],
            'limbs.tail' => ['limbs' => ['tail' => ['motor' => 'normal']]],
            'limbs.left_hind.deep_pain' => ['limbs' => ['left_hind' => ['deep_pain' => 'normal']]],
            'spine.painful_regions.0' => ['spine' => ['painful_regions' => ['tail']]],
            'spine.cutoff_at' => ['spine' => ['cutaneous_trunci' => 'cutoff']],
            'gait_score' => ['gait_score' => 6],
            'mental_status' => ['mental_status' => 'confus'],
            'cranial_nerves.smell' => ['cranial_nerves' => ['smell' => ['left' => 'normal']]],
            'cranial_nerves.menace.left' => ['cranial_nerves' => ['menace' => ['left' => 'increased']]],
        ];
        foreach ($cas as $champ => $corps) {
            $this->postJson($this->uri(), $corps, $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors($champ);
        }
        $this->postJson($this->uri(), ['spine' => ['cutaneous_trunci' => 'cutoff', 'cutoff_at' => 'C3']], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('spine.cutoff_at');

        $this->assertSame(0, NeuroExam::count());
    }

    public function test_la_vertebre_de_coupure_tombe_quand_le_reflexe_est_normal(): void
    {
        $this->postJson($this->uri(), ['spine' => ['cutaneous_trunci' => 'normal', 'cutoff_at' => 'L2']], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.spine.cutoff_at', null);
    }

    public function test_l_examen_du_jour_se_modifie_et_s_efface(): void
    {
        $id = $this->postJson($this->uri(), $this->examenComplet(), $this->enTetes($this->jeton))->json('data.id');

        $this->patchJson($this->uri($id), ['limbs' => ['right_hind' => ['motor' => 'non_ambulatory_paresis', 'deep_pain' => 'present']], 'gait_score' => 3], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.limbs.right_hind.motor', 'non_ambulatory_paresis')
            ->assertJsonPath('data.limbs.right_hind.proprioception', null)
            ->assertJsonPath('data.gait_score', 3)
            // Ce qui n'est pas envoyé reste
            ->assertJsonPath('data.spine.cutoff_at', 'L1')
            ->assertJsonPath('data.mental_status', 'obtunded');

        $this->deleteJson($this->uri($id), [], $this->enTetes($this->jeton))->assertNoContent();
        $this->assertSame(0, NeuroExam::count());
    }

    public function test_un_examen_passe_se_relit_sans_se_modifier(): void
    {
        $id = $this->postJson($this->uri(), $this->examenComplet(), $this->enTetes($this->jeton))->json('data.id');
        NeuroExam::whereKey($id)->update(['day' => aujourdhui()->subDay()->toDateString()]);

        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.0.is_editable', false);
        $this->patchJson($this->uri($id), ['gait_score' => 5], $this->enTetes($this->jeton))->assertForbidden();
        $this->deleteJson($this->uri($id), [], $this->enTetes($this->jeton))->assertForbidden();
        $this->assertSame(2, NeuroExam::find($id)->gait_score);
    }

    public function test_un_examen_d_une_autre_organisation_se_relit_sans_se_modifier(): void
    {
        $id = $this->postJson($this->uri(), $this->examenComplet(), $this->enTetes($this->jeton))->json('data.id');
        // Hors de toute requête : l'organisation d'une autre clinique
        app(ContexteClinique::class)->oublier();
        NeuroExam::whereKey($id)->update(['source_organisation_id' => Organisation::factory()->create()->id]);

        $this->getJson($this->uri(), $this->enTetes($this->jeton))->assertJsonPath('data.0.is_editable', false);
        $this->patchJson($this->uri($id), ['gait_score' => 5], $this->enTetes($this->jeton))->assertForbidden();
    }

    public function test_les_examens_vont_du_plus_recent_au_plus_ancien(): void
    {
        $premier = $this->postJson($this->uri(), ['gait_score' => 0], $this->enTetes($this->jeton))->json('data.id');
        NeuroExam::whereKey($premier)->update(['day' => aujourdhui()->subDays(3)->toDateString()]);
        $second = $this->postJson($this->uri(), ['gait_score' => 3], $this->enTetes($this->jeton))->json('data.id');

        $this->getJson($this->uri(), $this->enTetes($this->jeton))
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.1.id', $premier);
    }

    public function test_une_autre_organisation_ne_lit_ni_ne_change_rien(): void
    {
        $id = $this->postJson($this->uri(), $this->examenComplet(), $this->enTetes($this->jeton))->json('data.id');

        app(ContexteClinique::class)->oublier();
        ['jeton' => $autre] = $this->employeAvecJeton(Clinique::factory()->create());
        $this->getJson($this->uri(), $this->enTetes($autre))->assertNotFound();
        $this->postJson($this->uri(), [], $this->enTetes($autre))->assertNotFound();
        $this->patchJson($this->uri($id), ['gait_score' => 5], $this->enTetes($autre))->assertNotFound();
        $this->deleteJson($this->uri($id), [], $this->enTetes($autre))->assertNotFound();
        $this->assertSame(1, NeuroExam::count());
    }

    public function test_aucune_localisation_n_est_proposee(): void
    {
        $donnees = $this->postJson($this->uri(), $this->examenComplet(), $this->enTetes($this->jeton))->json('data');

        $this->assertEqualsCanonicalizing(
            ['id', 'day', 'by_name', 'is_editable', 'mental_status', 'gait_score', 'limbs', 'spine', 'cranial_nerves', 'note', 'created_at'],
            array_keys($donnees),
        );
    }
}
