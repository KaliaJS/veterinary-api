<?php

namespace Tests\Feature\Equipe;

use App\Models\Clinique;
use App\Models\Employe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Rotation des gardes de week-end : dérivée (semaine modulo l'effectif
 * vétérinaire), six week-ends à venir ; seule une réattribution est
 * stockée et elle prime sur la rotation.
 */
class GardeWeekendTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_la_rotation_derive_six_week_ends_sur_l_effectif_veterinaire(): void
    {
        Employe::factory()->count(2)->dans($this->clinique)->create();
        Employe::factory()->asv()->dans($this->clinique)->create();

        $reponse = $this->getJson('/api/v1/equipe/gardes', $this->enTetes($this->jeton));

        $reponse->assertOk()->assertJsonCount(6, 'data');

        $veterinaires = Employe::withoutGlobalScopes()
            ->whereRelation('cliniques', 'cliniques.id', $this->clinique->id)
            ->where('role_libelle', 'like', 'Vétérinaire%')
            ->pluck('id');

        foreach ($reponse->json('data') as $rang => $garde) {
            $samedi = CarbonImmutable::parse($garde['samedi_le']);

            $this->assertTrue($samedi->isSaturday());
            $this->assertContains($garde['employe_id'], $veterinaires);
            $this->assertFalse($garde['est_reattribuee']);

            if ($rang > 0) {
                $this->assertSame(
                    CarbonImmutable::parse($reponse->json("data.{$rang}.samedi_le"))->toDateString(),
                    CarbonImmutable::parse($reponse->json('data.0.samedi_le'))->addWeeks($rang)->toDateString(),
                );
            }
        }

        // Deux week-ends consécutifs ne reviennent pas au même
        // vétérinaire (rotation modulo 3).
        $this->assertNotSame($reponse->json('data.0.employe_id'), $reponse->json('data.1.employe_id'));
    }

    public function test_l_echange_reattribue_un_week_end_et_prime_sur_la_rotation(): void
    {
        Employe::factory()->count(2)->dans($this->clinique)->create();
        $entetes = $this->enTetes($this->jeton);

        $avant = $this->getJson('/api/v1/equipe/gardes', $entetes)->json('data.0');

        $remplacant = Employe::withoutGlobalScopes()
            ->whereRelation('cliniques', 'cliniques.id', $this->clinique->id)
            ->where('id', '!=', $avant['employe_id'])
            ->first();

        $reponse = $this->putJson('/api/v1/equipe/gardes', [
            'samedi_le' => $avant['samedi_le'],
            'employe_id' => $remplacant->id,
        ], $entetes);

        $reponse->assertOk()
            ->assertJsonPath('data.0.employe_id', $remplacant->id)
            ->assertJsonPath('data.0.est_reattribuee', true);

        $this->assertDatabaseHas('gardes_weekend', [
            'clinique_id' => $this->clinique->id,
            'employe_id' => $remplacant->id,
        ]);
    }

    public function test_une_reattribution_hors_samedi_est_refusee(): void
    {
        $this->putJson('/api/v1/equipe/gardes', [
            'samedi_le' => CarbonImmutable::today()->next(CarbonImmutable::SATURDAY)->addDay()->toDateString(),
            'employe_id' => Employe::factory()->dans($this->clinique)->create()->id,
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('samedi_le');
    }
}
