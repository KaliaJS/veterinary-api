<?php

namespace Tests\Feature\Diagnostic;

use App\Auth\ContexteClinique;
use App\Auth\RolesParDefaut;
use App\Enums\StatutEnregistrement;
use App\Enums\StatutPartie;
use App\Jobs\SuggererDiagnostic;
use App\Mail\PlafondDiagnosticOrganisation;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\Employe;
use App\Models\EnregistrementConsultation;
use App\Models\Organisation;
use App\Models\PartieEnregistrement;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\RendezVous;
use App\Models\Role;
use App\Models\SuggestionDiagnostique;
use App\Services\BudgetAnthropic;
use App\Services\Diagnostic\Suggesteur;
use App\Services\Diagnostic\SuggestionImpossible;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\SuggesteurFactice;
use Tests\TestCase;

/**
 * Suggestion diagnostique : elle part du compte rendu à l'écran, de la
 * transcription, du signalement (sans nom) et des comptes rendus
 * précédents ; rendue, elle est facturée, en échec non. Abonnement,
 * plafond et alertes, isolation d'organisation, réglages.
 */
class SuggestionDiagnostiqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private SuggesteurFactice $suggesteur;

    private Clinique $clinique;

    private Organisation $organisation;

    private Patient $patient;

    private RendezVous $rendezVous;

    private Employe $employe;

    private string $jeton;

    private const SOAP = ['s' => 'Boite depuis trois jours.', 'o' => 'Chaleur au genou droit.', 'a' => '', 'p' => ''];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Suggesteur::class, $this->suggesteur = new SuggesteurFactice);

        $this->clinique = Clinique::factory()->create();
        $this->organisation = $this->clinique->societe->organisation;
        $this->organisation->forceFill(['statut_abonnement' => 'actif', 'periode_debut' => now('Europe/Zurich')->subDays(3)->toDateString(), 'periode_fin' => now('Europe/Zurich')->addDays(27)->toDateString()])->save();

        $this->patient = Patient::factory()->create([
            'client_id' => Client::factory()->create(['organisation_id' => $this->organisation->id])->id,
            'nom' => 'Pacha',
            'race' => 'Bouvier bernois',
            'est_sterilise' => true,
            'ne_le' => now()->subYears(6)->toDateString(),
        ]);
        Pesee::query()->create(['patient_id' => $this->patient->id, 'poids_kg' => 48.5, 'pesee_le' => now()->subDay()->toDateString()]);
        $this->rendezVous = RendezVous::factory()->pourPatient($this->patient)->create([
            'clinique_id' => $this->clinique->id,
            'reason' => 'Boiterie',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->subMinutes(30),
        ]);

        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function demander(array $soap = self::SOAP, ?string $jeton = null): TestResponse
    {
        return $this->postJson("/api/v1/rendez-vous/{$this->rendezVous->id}/suggestion-diagnostique", $soap, $this->enTetes($jeton ?? $this->jeton));
    }

    public function test_la_suggestion_part_du_compte_rendu_a_l_ecran_et_se_facture_une_fois_rendue(): void
    {
        // Un compte rendu précédent du patient, et la transcription de cette consultation
        $avant = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'reason' => 'Vaccin', 'starts_at' => now()->subMonths(3), 'ends_at' => now()->subMonths(3)->addMinutes(20)]);
        CompteRendu::query()->create(['rendez_vous_id' => $avant->id, 's' => '', 'o' => '', 'a' => 'Bonne santé générale.', 'p' => 'Rappel dans un an.']);
        $enregistrement = EnregistrementConsultation::query()->create([
            'organisation_id' => $this->organisation->id, 'clinique_id' => $this->clinique->id, 'rendez_vous_id' => $this->rendezVous->id,
            'statut' => StatutEnregistrement::Pret, 'brouillon' => ['s' => '', 'o' => '', 'a' => '', 'p' => '', 'doutes' => []],
        ]);
        PartieEnregistrement::query()->create([
            'enregistrement_consultation_id' => $enregistrement->id, 'numero' => 1, 'statut' => StatutPartie::Transcrite, 'format' => 'audio/mp4', 'morceaux' => 1,
            'duree_secondes' => 60, 'segments' => [['voix' => 0, 'debut' => 3.0, 'fin' => 6.0, 'texte' => 'Il boite depuis lundi.']],
        ]);

        $this->demander(['s' => ' Boite depuis trois jours. ', 'o' => 'Chaleur au genou droit.', 'a' => null, 'p' => ''])
            ->assertCreated()
            ->assertJsonPath('data.statut', 'prete')
            ->assertJsonPath('data.avec_transcription', true)
            ->assertJsonPath('data.contenu.hypotheses.0.nom', 'Rupture du ligament croisé crânial (RLCCr)')
            ->assertJsonPath('data.contenu.hypotheses.0.probabilite', 'elevee')
            ->assertJsonPath('data.contenu.alertes.0.nom', 'Ostéosarcome')
            ->assertJsonPath('data.montant', 0.5)
            ->assertJsonPath('data.soap.s', 'Boite depuis trois jours.');

        $appel = $this->suggesteur->appels[0];
        $this->assertSame(['s' => 'Boite depuis trois jours.', 'o' => 'Chaleur au genou droit.', 'a' => '', 'p' => ''], $appel['soap']);
        $this->assertSame('Bouvier bernois', $appel['signalement']['Race']);
        $this->assertSame('48.5 kg, pesé le '.now()->subDay()->format('d.m.Y'), $appel['signalement']['Poids']);
        $this->assertStringContainsString('stérilisé', $appel['signalement']['Sexe']);
        $this->assertSame('6 ans', $appel['signalement']['Âge']);
        $this->assertNotContains('Pacha', $appel['signalement']);
        $this->assertSame([['date' => now()->subMonths(3)->format('d.m.Y'), 'motif' => 'Vaccin', 'a' => 'Bonne santé générale.', 'p' => 'Rappel dans un an.']], $appel['antecedents']);
        $this->assertSame('Il boite depuis lundi.', $appel['segments'][0]['texte']);

        $this->getJson("/api/v1/rendez-vous/{$this->rendezVous->id}/suggestion-diagnostique", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.contenu.examens.0.examen', 'Radiographie du grasset');

        // Chiffrée en base, comptée dans la période
        $this->assertStringNotContainsString('ligament', (string) SuggestionDiagnostique::query()->toBase()->value('contenu'));
        $this->getJson('/api/v1/organisation/diagnostic', $this->enTetes($this->administrateur()))
            ->assertOk()
            ->assertJsonPath('data.suggestions_periode', 1)
            ->assertJsonPath('data.montant_periode', 0.5);
    }

    /** Une suggestion reprise après une panne compte une fois de plus dans le budget Anthropic, la première non (24.10.2026). */
    public function test_une_suggestion_reprise_compte_dans_le_budget_anthropic(): void
    {
        config(['services.anthropic.cout_redaction_usd' => 0, 'services.anthropic.cout_suggestion_usd' => 0.02]);
        $budget = app(BudgetAnthropic::class);
        $suggerer = function (int $essai) use ($budget): float {
            $suggestion = SuggestionDiagnostique::withoutGlobalScopes()->create([
                'organisation_id' => $this->organisation->id, 'clinique_id' => $this->clinique->id, 'rendez_vous_id' => $this->rendezVous->id,
                'statut' => 'en_cours', 'soap' => self::SOAP, 'avec_transcription' => false,
            ]);
            $avant = $budget->depense();
            $tache = (new SuggererDiagnostic($suggestion->id))->withFakeQueueInteractions();
            $tache->job->attempts = $essai;
            app()->call([$tache, 'handle']);

            return round($budget->depense() - $avant, 2);
        };

        $this->assertSame(0.0, $suggerer(1));
        $this->assertSame(0.02, $suggerer(2));
    }

    public function test_sans_compte_rendu_ni_transcription_rien_ne_part(): void
    {
        $this->demander(['s' => ' ', 'o' => '', 'a' => '', 'p' => 'Repos.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('compte_rendu');
        $this->assertSame([], $this->suggesteur->appels);
    }

    public function test_un_echec_n_est_pas_facture_et_se_redemande(): void
    {
        $this->suggesteur->erreur = new SuggestionImpossible('Refus.');

        $this->demander()->assertCreated()->assertJsonPath('data.statut', 'erreur')->assertJsonPath('data.montant', 0);

        $this->suggesteur->erreur = null;
        $this->demander()->assertCreated()->assertJsonPath('data.statut', 'prete');
        $this->assertSame(2, SuggestionDiagnostique::query()->count());
        $this->assertSame(50, (int) SuggestionDiagnostique::query()->sum('montant_centimes'));
    }

    public function test_sans_abonnement_ou_au_plafond_rien_ne_part_et_les_administrateurs_sont_prevenus(): void
    {
        Mail::fake();
        $admin = $this->administrateur();

        // Plafond d'un franc : deux suggestions à 0,50
        $this->organisation->forceFill(['plafond_diagnostic' => 100])->save();
        $this->demander()->assertCreated();
        Mail::assertNothingSent();
        $this->demander()->assertCreated();
        Mail::assertSent(PlafondDiagnosticOrganisation::class, 1);
        Mail::assertSent(PlafondDiagnosticOrganisation::class, fn ($mail) => $mail->seuil === 100 && $mail->hasTo($this->employe->user->email));

        $this->demander()->assertUnprocessable()->assertJsonValidationErrors('diagnostic');
        $this->getJson('/api/v1/diagnostic', $this->enTetes($admin))->assertOk()->assertJsonPath('data.disponible', false);

        // Renouvellement refusé : l'accès reste ouvert, pas les suggestions
        $this->organisation->forceFill(['plafond_diagnostic' => 5000, 'statut_abonnement' => 'impaye'])->save();
        $this->demander()
            ->assertUnprocessable()
            ->assertJsonPath('errors.diagnostic.0', fn ($message) => str_starts_with($message, 'Une facture de votre abonnement attend son règlement'));
    }

    public function test_une_autre_organisation_ne_voit_ni_ne_demande(): void
    {
        $this->demander()->assertCreated();
        $this->app->make(ContexteClinique::class)->oublier();
        ['jeton' => $autre] = $this->employeAvecJeton(Clinique::factory()->create());

        $this->getJson("/api/v1/rendez-vous/{$this->rendezVous->id}/suggestion-diagnostique", $this->enTetes($autre))->assertNotFound();
        $this->demander(self::SOAP, $autre)->assertNotFound();
    }

    public function test_etat_et_plafond_de_l_organisation(): void
    {
        $this->getJson('/api/v1/diagnostic', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.disponible', true)
            ->assertJsonPath('data.tarif', 0.5);

        $this->putJson('/api/v1/organisation/diagnostic', ['plafond' => 20], $this->enTetes($this->jeton))->assertForbidden();
        $this->putJson('/api/v1/organisation/diagnostic', ['plafond' => 20.5], $this->enTetes($this->administrateur()))
            ->assertOk()
            ->assertJsonPath('data.plafond', 20.5);
        $this->assertSame(2050, $this->organisation->refresh()->plafond_diagnostic);
    }

    /** L'employé du jeton devient administrateur : le domaine organisation s'ouvre. */
    private function administrateur(): string
    {
        $this->employe->update(['role_id' => Role::withoutGlobalScope('organisation')
            ->where('organisation_id', $this->organisation->id)
            ->where('nom', RolesParDefaut::ADMINISTRATEUR)
            ->value('id')]);

        return $this->jeton;
    }
}
