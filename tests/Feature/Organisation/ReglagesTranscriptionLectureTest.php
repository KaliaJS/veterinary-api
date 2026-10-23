<?php

namespace Tests\Feature\Organisation;

use App\Models\Clinique;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Lecture des réglages de transcription de l'organisation (plafond,
 * mots rares, consommation du mois) : ceux de l'organisation du jeton,
 * vus pareil depuis une clinique sœur, jamais ceux d'une autre ; réservée
 * au domaine organisation.
 */
class ReglagesTranscriptionLectureTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    public function test_l_administrateur_lit_les_reglages_et_retrouve_ceux_qu_il_a_enregistres(): void
    {
        $this->getJson('/api/v1/organisation/transcription', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.termes', [])
            ->assertJsonPath('data.minutes_periode', 0)
            ->assertJsonPath('data.montant_periode', 0)
            ->assertJsonStructure(['data' => ['disponible', 'motif', 'periode_debut', 'plafond', 'tarif_minute', 'regles' => ['morceau_max_ko']]]);

        $this->putJson('/api/v1/organisation/transcription', ['plafond' => 80, 'termes' => ['Metacam', 'Beloc Zok']], $this->enTetes($this->jeton))
            ->assertOk();

        $this->getJson('/api/v1/organisation/transcription', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.plafond', 80)
            ->assertJsonPath('data.termes', ['Metacam', 'Beloc Zok']);
    }

    public function test_une_clinique_soeur_lit_les_memes_reglages_une_autre_organisation_les_siens(): void
    {
        $etrangere = Clinique::factory()->create();
        Organisation::query()->whereKey($this->clinique->societe->organisation_id)
            ->update(['plafond_transcription' => 12345, 'termes_transcription' => json_encode(['Convenia'])]);

        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $jetonSoeur] = $this->administrateurAvecJeton($soeur);
        $this->getJson('/api/v1/organisation/transcription', $this->enTetes($jetonSoeur))
            ->assertOk()
            ->assertJsonPath('data.plafond', 123.45)
            ->assertJsonPath('data.termes', ['Convenia']);

        ['jeton' => $jetonEtranger] = $this->administrateurAvecJeton($etrangere);
        $this->getJson('/api/v1/organisation/transcription', $this->enTetes($jetonEtranger))
            ->assertOk()
            ->assertJsonPath('data.termes', [])
            ->assertJsonPath('data.plafond', fn ($plafond) => $plafond !== 123.45);
    }

    public function test_un_veterinaire_ne_lit_pas_les_reglages_de_l_organisation(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/organisation/transcription', $this->enTetes($jeton))->assertForbidden();
    }
}
