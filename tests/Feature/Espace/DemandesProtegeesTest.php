<?php

namespace Tests\Feature\Espace;

use App\Mail\CodeDemandeRendezVous;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\DeposeSansCompte;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Demandes de rendez-vous protégées (23.10.2026) : sans compte, un
 * captcha puis un code reçu à l'adresse ; avec un compte, une adresse
 * prouvée ; pour tous, une heure à venir dans le mois, à la minute
 * ronde, et pas plus de cinq demandes ouvertes par clinique.
 */
class DemandesProtegeesTest extends TestCase
{
    use DeposeSansCompte;
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private CarbonImmutable $mardi;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // La clinique valide chaque demande : elles restent dans sa file, sans agenda à remplir
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich', 'rdv_sans_compte' => true, 'rdv_a_valider' => true]);
        HoraireOuverture::factory()->create(['clinique_id' => $this->clinique->id]);
        LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->mardi = CarbonImmutable::now('Europe/Zurich')->addDays(2)->next(CarbonImmutable::TUESDAY)->setTime(10, 0);
    }

    public function test_sans_captcha_valide_aucun_code_ne_part(): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this->postJson('/api/v1/public/demandes/code', ['clinique_id' => $this->clinique->id, 'email' => 'victime@exemple.ch', 'captcha' => 'faux'])
            ->assertUnprocessable()->assertJsonValidationErrors('captcha');
        Mail::assertNotSent(CodeDemandeRendezVous::class);
    }

    public function test_sans_le_bon_code_la_demande_ne_part_pas_et_le_code_ne_sert_qu_une_fois(): void
    {
        $code = $this->codeDeDemande($this->clinique->id, 'lea@exemple.ch');
        $faux = $code === '111111' ? '222222' : '111111';

        $this->postJson('/api/v1/public/demandes', $this->demande(['code' => $faux]))->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('demandes_rendez_vous', 0);

        $this->postJson('/api/v1/public/demandes', $this->demande(['code' => $code]))->assertCreated();
        $this->postJson('/api/v1/public/demandes', $this->demande(['code' => $code, 'debut_souhaite' => $this->mardi->addHour()->toIso8601String()]))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_une_heure_hors_du_mois_ou_hors_de_la_grille_est_refusee(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        foreach ([$this->mardi->addDays(40), $this->mardi->setTime(10, 7)] as $heure) {
            $this->postJson('/api/v1/espace/demandes', $this->demande(['debut_souhaite' => $heure->toIso8601String()]), $this->enTetes($jeton))
                ->assertUnprocessable()->assertJsonValidationErrors('debut_souhaite');
        }
    }

    public function test_pas_plus_de_cinq_demandes_ouvertes_par_clinique(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/espace/demandes', $this->demande(['debut_souhaite' => $this->mardi->addMinutes(30 * $i)->toIso8601String()]), $this->enTetes($jeton))
                ->assertCreated();
        }

        $this->postJson('/api/v1/espace/demandes', $this->demande(['debut_souhaite' => $this->mardi->addHours(4)->toIso8601String()]), $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('clinique_id');
        $this->assertSame(5, DemandeRendezVous::withoutGlobalScopes()->count());
    }

    public function test_un_compte_a_l_adresse_non_prouvee_ne_reserve_pas(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton(['email_verified_at' => null]);

        $this->postJson('/api/v1/espace/demandes', $this->demande(), $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    /** @param  array<string, mixed>  $champs */
    private function demande(array $champs = []): array
    {
        return [
            'clinique_id' => $this->clinique->id,
            'patient_name' => 'Pacha',
            'espece' => 'CH',
            'motif' => 'Vaccin annuel',
            'mode' => 'place',
            'debut_souhaite' => $this->mardi->toIso8601String(),
            'nom_proprietaire' => 'Léa Rochat',
            'email' => 'lea@exemple.ch',
            'telephone' => '+41 79 123 45 67',
            ...$champs,
        ];
    }
}
