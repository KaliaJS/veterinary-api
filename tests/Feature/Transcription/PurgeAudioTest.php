<?php

namespace Tests\Feature\Transcription;

use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Models\Clinique;
use App\Models\EnregistrementConsultation;
use App\Models\PartieEnregistrement;
use App\Models\RendezVous;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Purge nocturne de l'audio (`transcriptions:purger`) : seul l'audio des
 * parties jamais transcrites et trop anciennes s'efface (7 jours, 2 pour
 * une partie en erreur), morceaux en attente compris ; tout le reste du
 * disque privé reste. La commande tourne hors contexte : toutes les
 * organisations sont purgées. Le cas courant (reçue ancienne, erreur de
 * trois jours) est dans EnregistrementConsultationTest.
 */
class PurgeAudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prive');
        // Les délais se comptent à la minute : l'horloge ne bouge pas pendant le test
        $this->freezeTime();
    }

    public function test_les_morceaux_d_un_envoi_abandonne_s_effacent_et_la_partie_passe_en_erreur(): void
    {
        $partie = $this->partie($this->enregistrement(), 1, StatutPartie::Envoi, joursAvant: 8, fichier: false);
        Storage::disk('prive')->put($partie->dossierMorceaux().'/00000', 'AA');
        Storage::disk('prive')->put($partie->dossierMorceaux().'/00001', 'BB');

        $this->artisan('transcriptions:purger')->expectsOutput('1 partie(s) purgée(s).')->assertSuccessful();

        $this->assertSame([], Storage::disk('prive')->allFiles($partie->dossierMorceaux()));
        $partie->refresh();
        $this->assertSame(StatutPartie::Erreur, $partie->statut);
        $this->assertNull($partie->fichier);
    }

    public function test_les_delais_se_comptent_a_la_minute_pres(): void
    {
        $enregistrement = $this->enregistrement();
        $justeAvant = $this->partie($enregistrement, 1, StatutPartie::Recue, joursAvant: 7, minutes: -1);
        $justeApres = $this->partie($enregistrement, 2, StatutPartie::Recue, joursAvant: 7, minutes: 1);
        $erreurJusteAvant = $this->partie($enregistrement, 3, StatutPartie::Erreur, joursAvant: 2, minutes: -1);
        $erreurJusteApres = $this->partie($enregistrement, 4, StatutPartie::Erreur, joursAvant: 2, minutes: 1);

        $this->artisan('transcriptions:purger')->expectsOutput('2 partie(s) purgée(s).')->assertSuccessful();

        Storage::disk('prive')->assertExists([$justeAvant->fichier, $erreurJusteAvant->fichier]);
        Storage::disk('prive')->assertMissing([$justeApres->fichier, $erreurJusteApres->fichier]);
        $this->assertSame(StatutPartie::Recue, $justeAvant->refresh()->statut);
    }

    public function test_seul_l_audio_concerne_s_efface(): void
    {
        $enregistrement = $this->enregistrement();
        $ancienne = $this->partie($enregistrement, 1, StatutPartie::Recue, joursAvant: 30);
        // Une partie transcrite n'a plus d'audio : même ancienne, la purge ne la touche pas
        $transcrite = $this->partie($enregistrement, 2, StatutPartie::Transcrite, joursAvant: 30, fichier: false);
        // Une erreur déjà purgée ne se recompte pas
        $dejaPurgee = $this->partie($enregistrement, 3, StatutPartie::Erreur, joursAvant: 30, fichier: false);
        $recente = $this->partie($this->enregistrement(), 1, StatutPartie::Recue, joursAvant: 1);
        Storage::disk('prive')->put('documents/1/radiographie.pdf', 'PDF');
        Storage::disk('prive')->put('photos-cliniques/1/vitrine-480.webp', 'WEBP');

        $this->artisan('transcriptions:purger')->expectsOutput('1 partie(s) purgée(s).')->assertSuccessful();

        Storage::disk('prive')->assertMissing($ancienne->fichier);
        Storage::disk('prive')->assertExists([$recente->fichier, 'documents/1/radiographie.pdf', 'photos-cliniques/1/vitrine-480.webp']);
        $this->assertSame(StatutPartie::Transcrite, $transcrite->refresh()->statut);
        $this->assertTrue($dejaPurgee->refresh()->updated_at->lt(now()->subDays(29)));
    }

    public function test_toutes_les_organisations_sont_purgees(): void
    {
        $ici = $this->partie($this->enregistrement(), 1, StatutPartie::Recue, joursAvant: 8);
        $ailleurs = $this->partie($this->enregistrement(), 1, StatutPartie::Recue, joursAvant: 8);

        $this->artisan('transcriptions:purger')->expectsOutput('2 partie(s) purgée(s).')->assertSuccessful();

        $this->assertNotSame($ici->enregistrement->organisation_id, $ailleurs->enregistrement->organisation_id);
        Storage::disk('prive')->assertMissing([$ici->fichier, $ailleurs->fichier]);
    }

    public function test_le_delai_suit_la_configuration(): void
    {
        config(['transcription.conservation_audio_jours' => 1]);
        $partie = $this->partie($this->enregistrement(), 1, StatutPartie::Recue, joursAvant: 2);

        $this->artisan('transcriptions:purger')->assertSuccessful();

        Storage::disk('prive')->assertMissing($partie->fichier);
    }

    /** Un enregistrement dans sa propre organisation. */
    private function enregistrement(): EnregistrementConsultation
    {
        $clinique = Clinique::factory()->create();

        return EnregistrementConsultation::withoutGlobalScopes()->create([
            'organisation_id' => $clinique->societe->organisation_id,
            'clinique_id' => $clinique->id,
            'rendez_vous_id' => RendezVous::factory()->create(['clinique_id' => $clinique->id])->id,
            'statut' => StatutEnregistrement::Transcription,
        ]);
    }

    /** Une partie dont la dernière modification remonte à tant de jours (et de minutes en plus ou en moins). */
    private function partie(EnregistrementConsultation $enregistrement, int $numero, StatutPartie $statut, int $joursAvant, int $minutes = 0, bool $fichier = true): PartieEnregistrement
    {
        $chemin = $fichier ? 'transcriptions/'.$enregistrement->id.'/'.$numero.'.audio' : null;
        $partie = PartieEnregistrement::query()->create([
            'enregistrement_consultation_id' => $enregistrement->id, 'numero' => $numero, 'statut' => $statut,
            'format' => 'audio/mp4', 'morceaux' => 2, 'fichier' => $chemin,
        ]);
        if ($chemin) {
            Storage::disk('prive')->put($chemin, 'AUDIO');
        }
        PartieEnregistrement::query()->whereKey($partie->id)->update(['updated_at' => now()->subDays($joursAvant)->addMinutes(-$minutes)]);

        return $partie->refresh();
    }
}
