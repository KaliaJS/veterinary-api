<?php

namespace Tests\Feature;

use App\Auth\ContexteClinique;
use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Jobs\SuggererDiagnostic;
use App\Jobs\TranscrirePartie;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\EnregistrementConsultation;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\PartieEnregistrement;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\RendezVous;
use App\Models\SuggestionDiagnostique;
use App\Services\Diagnostic\Suggesteur;
use App\Services\Transcription\Redacteur;
use App\Services\Transcription\Transcripteur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionProperty;
use Tests\Doubles\RedacteurFactice;
use Tests\Doubles\SuggesteurFactice;
use Tests\Doubles\TranscripteurFactice;
use Tests\TestCase;

/**
 * Les tâches en file (transcription, rédaction, suggestion) tournent dans
 * un worker, SANS contexte clinique : les scopes globaux y sont inactifs.
 * Elles doivent donc borner elles-mêmes leurs lectures à l'organisation
 * de leur enregistrement ou de leur rendez-vous, et le worker ne doit
 * jamais garder le contexte d'une tâche pour la suivante.
 */
class TachesEnFileContexteTest extends TestCase
{
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $soeur;

    private Clinique $etrangere;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('prive');

        $this->clinique = Clinique::factory()->create();
        $this->soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->etrangere = Clinique::factory()->create();

        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create(['nom' => 'Pacha', 'race' => 'Bouvier bernois']);
    }

    public function test_le_worker_oublie_le_contexte_clinique_entre_deux_taches(): void
    {
        app(ContexteClinique::class)->definir($this->clinique);

        // Ce que le worker appelle avant chaque tâche (QueueServiceProvider)
        $remiseAZero = (new ReflectionProperty(app('queue.worker'), 'resetScope'))->getValue(app('queue.worker'));
        $remiseAZero();

        $this->assertFalse(app(ContexteClinique::class)->estDefini());
    }

    public function test_la_transcription_hors_contexte_ne_prend_les_termes_que_de_son_organisation(): void
    {
        $this->clinique->societe->organisation->forceFill(['termes_transcription' => ['Beloc Zok']])->save();
        $this->etrangere->societe->organisation->forceFill(['termes_transcription' => ['Zylkène']])->save();
        $this->prescrire($this->clinique, 'Metacam 1,5 mg/ml');
        $this->prescrire($this->etrangere, 'Rimadyl 50 mg');

        $transcripteur = new TranscripteurFactice;
        $this->app->instance(Transcripteur::class, $transcripteur);
        $this->app->instance(Redacteur::class, new RedacteurFactice);

        $rendezVous = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id]);
        $enregistrement = EnregistrementConsultation::withoutGlobalScopes()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'clinique_id' => $this->clinique->id,
            'rendez_vous_id' => $rendezVous->id,
            'statut' => StatutEnregistrement::Transcription,
            'parties_attendues' => 1,
        ]);
        $partie = PartieEnregistrement::query()->create([
            'enregistrement_consultation_id' => $enregistrement->id, 'numero' => 1, 'statut' => StatutPartie::Recue,
            'format' => 'audio/mp4', 'morceaux' => 1, 'fichier' => 'transcriptions/'.$enregistrement->id.'/1.audio',
        ]);
        Storage::disk('prive')->put($partie->fichier, TranscripteurFactice::ENTETE.'AUDIO');

        app(ContexteClinique::class)->oublier();
        TranscrirePartie::dispatchSync($partie->id);

        $termes = $transcripteur->appels[0]['termes'];
        $this->assertContains('Beloc Zok', $termes);
        $this->assertContains('Bouvier bernois', $termes);
        $this->assertContains('Metacam', $termes);
        $this->assertNotContains('Zylkène', $termes);
        $this->assertNotContains('Rimadyl', $termes);

        $this->assertSame(StatutPartie::Transcrite, $partie->refresh()->statut);
        Storage::disk('prive')->assertMissing('transcriptions/'.$enregistrement->id.'/1.audio');
        $this->assertSame(StatutEnregistrement::Pret, EnregistrementConsultation::withoutGlobalScopes()->findOrFail($enregistrement->id)->statut);
    }

    public function test_la_suggestion_hors_contexte_ne_lit_que_les_antecedents_de_son_organisation(): void
    {
        $suggesteur = new SuggesteurFactice;
        $this->app->instance(Suggesteur::class, $suggesteur);

        $rendezVous = RendezVous::factory()->pourPatient($this->patient)->create([
            'clinique_id' => $this->clinique->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->subMinutes(30),
        ]);
        // Un compte rendu chez la clinique sœur : du même dossier, il compte
        $this->compteRenduAnterieur($this->soeur, 'Otite traitée.', 2);
        // Un rendez-vous d'une autre organisation qui porterait le même patient (donnée incohérente) : jamais lu
        $this->compteRenduAnterieur($this->etrangere, 'Ne doit pas sortir de son organisation.', 1);

        $suggestion = SuggestionDiagnostique::withoutGlobalScopes()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'clinique_id' => $this->clinique->id,
            'rendez_vous_id' => $rendezVous->id,
            'soap' => ['s' => 'Boite depuis trois jours.', 'o' => '', 'a' => '', 'p' => ''],
            'avec_transcription' => false,
        ]);

        app(ContexteClinique::class)->oublier();
        SuggererDiagnostic::dispatchSync($suggestion->id);

        $this->assertSame(['Otite traitée.'], array_column($suggesteur->appels[0]['antecedents'], 'a'));
        $this->assertSame('Bouvier bernois', $suggesteur->appels[0]['signalement']['Race']);
    }

    private function prescrire(Clinique $clinique, string $produit): void
    {
        $organisation = $clinique->societe->organisation_id;
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $clinique->id, 'organisation_id' => $organisation]);
        OrdonnanceLigne::factory()->create([
            'ordonnance_id' => $ordonnance->id,
            'produit_id' => Produit::factory()->medicament()->create(['organisation_id' => $organisation, 'nom' => $produit])->id,
        ]);
    }

    private function compteRenduAnterieur(Clinique $clinique, string $evaluation, int $moisAvant): void
    {
        $avant = RendezVous::factory()->pourPatient($this->patient)->create([
            'clinique_id' => $clinique->id,
            'reason' => 'Contrôle',
            'starts_at' => now()->subMonths($moisAvant),
            'ends_at' => now()->subMonths($moisAvant)->addMinutes(20),
        ]);
        CompteRendu::query()->create(['rendez_vous_id' => $avant->id, 's' => '', 'o' => '', 'a' => $evaluation, 'p' => 'Contrôle dans un mois.']);
    }
}
