<?php

namespace Tests\Feature\Tele;

use App\Auth\ContexteClinique;
use App\Events\SignalVisio;
use App\Events\TransfertAppelVideo;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SessionTeleconsultation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Transfert d'un appel vidéo (décision de Jim, 28.10.2026) : l'accueil
 * répond, puis passe l'appel à un vétérinaire. Le collègue choisi est
 * prévenu sur son canal ; il prend l'appel, ou le transfert s'annule.
 */
class TransfertAppelTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $accueil;

    private Employe $employeAccueil;

    private string $veto;

    private Employe $employeVeto;

    private User $userVeto;

    private SessionTeleconsultation $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->accueil, 'employe' => $this->employeAccueil] = $this->employeAvecJeton($this->clinique, ['nom' => 'Julie Muller', 'role_libelle' => 'ASV']);
        ['jeton' => $this->veto, 'employe' => $this->employeVeto, 'user' => $this->userVeto] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Anna Meyer', 'role_libelle' => 'Vétérinaire']);
        $organisation = $this->clinique->societe->organisation_id;
        $client = Client::factory()->create(['organisation_id' => $organisation]);
        $patient = Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $client->id, 'nom' => 'Félix']);
        $id = $this->postJson('/api/v1/tele/sessions', ['patient_id' => $patient->id, 'motif' => 'Contrôle'], $this->enTetes($this->accueil))->assertCreated()->json('data.id');
        $this->session = SessionTeleconsultation::withoutGlobalScopes()->findOrFail($id);
    }

    private function url(string $geste): string
    {
        return "/api/v1/tele/sessions/{$this->session->id}/{$geste}";
    }

    public function test_les_collegues_de_la_clinique_sont_proposes_les_veterinaires_d_abord(): void
    {
        app(ContexteClinique::class)->oublier();
        $this->employeAvecJeton($this->clinique, ['nom' => 'Paul Rochat', 'role_libelle' => 'ASV']);
        ['employe' => $suspendu] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Suspendu', 'role_libelle' => 'Vétérinaire']);
        $suspendu->forceFill(['acces_suspendu' => true])->save();
        ['employe' => $sansPlanning] = $this->employeAvecJeton($this->clinique, ['nom' => 'Compta', 'role_libelle' => 'Comptable']);
        $sansPlanning->update(['role_id' => Role::withoutGlobalScope('organisation')->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Compta', 'domaines' => ['facturation']])->id]);
        $this->employeAvecJeton(Clinique::factory()->create(), ['nom' => 'Dr Ailleurs', 'role_libelle' => 'Vétérinaire']);

        $noms = collect($this->getJson($this->url('collegues'), $this->enTetes($this->accueil))->assertOk()->json('data'))->pluck('nom')->all();

        $this->assertSame(['Dr Anna Meyer', 'Paul Rochat'], $noms);
    }

    public function test_transferer_previent_le_collegue_choisi(): void
    {
        Event::fake([TransfertAppelVideo::class, SignalVisio::class]);

        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeVeto->id], $this->enTetes($this->accueil))
            ->assertOk()->assertJsonPath('data.transfert_vers.nom', 'Dr Anna Meyer')->assertJsonPath('data.mene_par.nom', 'Julie Muller');

        Event::assertDispatched(TransfertAppelVideo::class, fn (TransfertAppelVideo $e): bool => $e->broadcastOn()[0]->name === 'private-utilisateur.'.$this->userVeto->id
            && $e->broadcastAs() === 'appel.transfert'
            && $e->broadcastWith() === ['etat' => 'demande', 'session_id' => $this->session->id, 'patient' => 'Félix', 'de' => 'Julie Muller']);
    }

    public function test_seul_celui_qui_mene_l_appel_le_transfere(): void
    {
        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeAccueil->id], $this->enTetes($this->veto))->assertForbidden();
    }

    public function test_le_collegue_est_de_la_clinique_avec_le_planning_et_pas_soi_meme(): void
    {
        app(ContexteClinique::class)->oublier();
        ['employe' => $ailleurs] = $this->employeAvecJeton(Clinique::factory()->create(), ['nom' => 'Dr Ailleurs']);

        $this->postJson($this->url('transferer'), ['employe_id' => $ailleurs->id], $this->enTetes($this->accueil))->assertUnprocessable();
        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeAccueil->id], $this->enTetes($this->accueil))->assertUnprocessable();
    }

    public function test_seul_le_collegue_designe_prend_l_appel_et_l_appel_change_de_mains(): void
    {
        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeVeto->id], $this->enTetes($this->accueil))->assertOk();
        app(ContexteClinique::class)->oublier();
        ['jeton' => $autre] = $this->employeAvecJeton($this->clinique, ['nom' => 'Paul Rochat']);
        Event::fake([SignalVisio::class]);

        $this->postJson($this->url('prendre'), [], $this->enTetes($autre))->assertForbidden();
        $this->postJson($this->url('prendre'), [], $this->enTetes($this->veto))
            ->assertOk()->assertJsonPath('data.mene_par.nom', 'Dr Anna Meyer')->assertJsonPath('data.transfert_vers', null);

        Event::assertDispatched(SignalVisio::class, fn (SignalVisio $e): bool => $e->broadcastOn()[0]->name === 'private-visio.'.$this->session->id
            && $e->broadcastWith()['type'] === 'releve' && $e->broadcastWith()['donnees']['par'] === $this->employeVeto->id);
    }

    public function test_le_transfert_s_annule_tant_qu_il_n_est_pas_pris(): void
    {
        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeVeto->id], $this->enTetes($this->accueil))->assertOk();
        Event::fake([TransfertAppelVideo::class]);

        $this->postJson($this->url('transfert/annuler'), [], $this->enTetes($this->accueil))->assertOk()->assertJsonPath('data.transfert_vers', null);

        Event::assertDispatched(TransfertAppelVideo::class, fn (TransfertAppelVideo $e): bool => $e->broadcastWith()['etat'] === 'annule');
        $this->postJson($this->url('prendre'), [], $this->enTetes($this->veto))->assertUnprocessable();
    }

    public function test_un_appel_termine_ne_se_transfere_pas(): void
    {
        $this->session->forceFill(['terminee_le' => now()])->save();

        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeVeto->id], $this->enTetes($this->accueil))->assertStatus(410);
    }
}
