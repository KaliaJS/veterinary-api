<?php

namespace Tests\Feature\Tele;

use App\Auth\ContexteClinique;
use App\Auth\EmetteurJetons;
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
            && $e->broadcastWith() === ['etat' => 'demande', 'session_id' => $this->session->id, 'patient' => 'Félix', 'de' => 'Julie Muller', 'clinique_id' => $this->clinique->id, 'clinique' => $this->clinique->nom]);
    }

    public function test_seul_celui_qui_mene_l_appel_le_transfere(): void
    {
        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeAccueil->id], $this->enTetes($this->veto))
            ->assertStatus(409)->assertJsonPath('message', 'Julie Muller mène cet appel.');
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

    /** « Les vétérinaires d'abord », même quand leur nom vient plus loin dans l'alphabet */
    public function test_les_veterinaires_viennent_avant_quel_que_soit_leur_nom(): void
    {
        app(ContexteClinique::class)->oublier();
        $this->employeAvecJeton($this->clinique, ['nom' => 'Alice Aebi', 'role_libelle' => 'ASV']);
        $this->employeAvecJeton($this->clinique, ['nom' => 'Zoé Zurbriggen', 'role_libelle' => 'Vétérinaire']);

        $noms = collect($this->getJson($this->url('collegues'), $this->enTetes($this->accueil))->assertOk()->json('data'))->pluck('nom')->all();

        $this->assertSame(['Dr Anna Meyer', 'Zoé Zurbriggen', 'Alice Aebi'], $noms);
    }

    /** « De la clinique » : un collègue de la clinique sœur seulement n'est ni proposé ni accepté */
    public function test_un_collegue_de_la_clinique_soeur_seulement_n_est_pas_propose(): void
    {
        app(ContexteClinique::class)->oublier();
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['employe' => $voisin] = $this->employeAvecJeton($soeur, ['nom' => 'Dr Voisin', 'role_libelle' => 'Vétérinaire']);

        $noms = collect($this->getJson($this->url('collegues'), $this->enTetes($this->accueil))->assertOk()->json('data'))->pluck('nom')->all();

        $this->assertNotContains('Dr Voisin', $noms);
        $this->postJson($this->url('transferer'), ['employe_id' => $voisin->id], $this->enTetes($this->accueil))->assertUnprocessable();
    }

    /** Un collègue dont le contrat est fini ne peut plus se connecter : il n'est pas proposé */
    public function test_un_collegue_au_contrat_termine_n_est_pas_propose(): void
    {
        app(ContexteClinique::class)->oublier();
        ['employe' => $parti] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Partie', 'role_libelle' => 'Vétérinaire']);
        $parti->forceFill(['fin_contrat_le' => now()->subDays(2)])->save();

        $noms = collect($this->getJson($this->url('collegues'), $this->enTetes($this->accueil))->assertOk()->json('data'))->pluck('nom')->all();

        $this->assertNotContains('Dr Partie', $noms);
    }

    /** Un collègue de deux cliniques apprend dans laquelle est l'appel ; il le prend depuis celle-ci */
    public function test_le_collegue_de_deux_cliniques_apprend_la_clinique_de_l_appel(): void
    {
        app(ContexteClinique::class)->oublier();
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->employeVeto->cliniques()->attach($soeur->id);
        Event::fake([TransfertAppelVideo::class, SignalVisio::class]);

        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeVeto->id], $this->enTetes($this->accueil))->assertOk();

        Event::assertDispatched(TransfertAppelVideo::class, fn (TransfertAppelVideo $e): bool => $e->broadcastWith()['clinique_id'] === $this->clinique->id
            && $e->broadcastWith()['clinique'] === $this->clinique->nom);
        app(ContexteClinique::class)->oublier();
        $ici = app(EmetteurJetons::class)->emettre($this->userVeto, $this->clinique->id)['jeton']->plainTextToken;
        $this->postJson($this->url('prendre'), [], $this->enTetes($ici))->assertOk();
    }

    /** Après la prise, celui qui a transféré (relève manquée, page rechargée) ne mène plus rien */
    public function test_celui_qui_a_transfere_ne_mene_plus_l_appel_apres_la_prise(): void
    {
        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeVeto->id], $this->enTetes($this->accueil))->assertOk();
        $this->postJson($this->url('prendre'), [], $this->enTetes($this->veto))->assertOk();

        $this->postJson($this->url('signal'), ['type' => 'pret', 'donnees' => []], $this->enTetes($this->accueil))->assertStatus(409)->assertJsonPath('message', 'Dr Anna Meyer mène cet appel.');
        $this->getJson($this->url('ice'), $this->enTetes($this->accueil))->assertStatus(409);
        $this->postJson($this->url('lien'), [], $this->enTetes($this->accueil))->assertStatus(409);
        $this->postJson($this->url('terminer'), ['cloturer' => false], $this->enTetes($this->accueil))->assertStatus(409);
        $this->postJson($this->url('terminer'), ['cloturer' => true], $this->enTetes($this->accueil))->assertStatus(409);

        $this->assertNull($this->session->refresh()->terminee_le);
        $this->postJson($this->url('signal'), ['type' => 'pret', 'donnees' => []], $this->enTetes($this->veto))->assertStatus(202);
    }

    /** La prise prévient l'ancien poste et les autres appareils du collègue, par leur canal */
    public function test_la_prise_previent_celui_qui_a_transfere_et_les_autres_appareils(): void
    {
        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeVeto->id], $this->enTetes($this->accueil))->assertOk();
        Event::fake([TransfertAppelVideo::class, SignalVisio::class]);

        $this->postJson($this->url('prendre'), [], $this->enTetes($this->veto))->assertOk();

        $accueilUser = $this->employeAccueil->user_id;
        foreach ([$accueilUser, $this->userVeto->id] as $userId) {
            Event::assertDispatched(TransfertAppelVideo::class, fn (TransfertAppelVideo $e): bool => $e->broadcastOn()[0]->name === 'private-utilisateur.'.$userId
                && $e->broadcastWith()['etat'] === 'pris' && $e->broadcastWith()['par'] === $this->employeVeto->id && $e->broadcastWith()['nom'] === 'Dr Anna Meyer');
        }
    }

    /** Un appel terminé pendant un transfert en attente prévient le collègue, et le transfert tombe */
    public function test_terminer_pendant_un_transfert_previent_le_collegue(): void
    {
        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeVeto->id], $this->enTetes($this->accueil))->assertOk();
        Event::fake([TransfertAppelVideo::class, SignalVisio::class]);

        $this->postJson($this->url('terminer'), ['cloturer' => false], $this->enTetes($this->accueil))->assertOk();

        Event::assertDispatched(TransfertAppelVideo::class, fn (TransfertAppelVideo $e): bool => $e->broadcastOn()[0]->name === 'private-utilisateur.'.$this->userVeto->id
            && $e->broadcastWith()['etat'] === 'termine');
        $this->assertNull($this->session->refresh()->transfert_vers_id);
    }

    /** Un second transfert pendant l'attente prévient le premier collègue que le sien est annulé */
    public function test_un_nouveau_transfert_previent_le_premier_collegue(): void
    {
        app(ContexteClinique::class)->oublier();
        ['employe' => $paul, 'user' => $userPaul] = $this->employeAvecJeton($this->clinique, ['nom' => 'Paul Rochat', 'role_libelle' => 'ASV']);
        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeVeto->id], $this->enTetes($this->accueil))->assertOk();
        Event::fake([TransfertAppelVideo::class]);

        $this->postJson($this->url('transferer'), ['employe_id' => $paul->id], $this->enTetes($this->accueil))->assertOk();

        Event::assertDispatched(TransfertAppelVideo::class, fn (TransfertAppelVideo $e): bool => $e->broadcastOn()[0]->name === 'private-utilisateur.'.$this->userVeto->id
            && $e->broadcastWith()['etat'] === 'annule');
        Event::assertDispatched(TransfertAppelVideo::class, fn (TransfertAppelVideo $e): bool => $e->broadcastOn()[0]->name === 'private-utilisateur.'.$userPaul->id
            && $e->broadcastWith()['etat'] === 'demande');
    }

    /** Prendre deux fois (autre appareil) : le message dit que l'appel est déjà à soi, pas « annulé » */
    public function test_prendre_deux_fois_dit_que_l_appel_est_deja_pris(): void
    {
        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeVeto->id], $this->enTetes($this->accueil))->assertOk();
        $this->postJson($this->url('prendre'), [], $this->enTetes($this->veto))->assertOk();

        $this->postJson($this->url('prendre'), [], $this->enTetes($this->veto))->assertUnprocessable()
            ->assertJsonPath('errors.transfert.0', 'Vous avez déjà pris cet appel.');
    }

    /** Annuler après la prise : refusé, l'appel reste au collègue qui l'a pris */
    public function test_annuler_apres_la_prise_ne_reprend_pas_l_appel(): void
    {
        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeVeto->id], $this->enTetes($this->accueil))->assertOk();
        $this->postJson($this->url('prendre'), [], $this->enTetes($this->veto))->assertOk();

        $this->postJson($this->url('transfert/annuler'), [], $this->enTetes($this->accueil))->assertStatus(409)->assertJsonPath('message', 'Dr Anna Meyer mène cet appel.');
        $this->assertSame($this->employeVeto->id, $this->session->refresh()->mene_par_id);
    }

    /** Prendre un appel terminé ou trop ancien : 410 */
    public function test_prendre_un_appel_termine_repond_410(): void
    {
        $this->postJson($this->url('transferer'), ['employe_id' => $this->employeVeto->id], $this->enTetes($this->accueil))->assertOk();
        $this->session->forceFill(['terminee_le' => now()])->save();

        $this->postJson($this->url('prendre'), [], $this->enTetes($this->veto))->assertStatus(410);
    }

    /** Le canal du compte : le sien seulement */
    public function test_le_canal_du_compte_n_est_ouvert_qu_a_son_titulaire(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'cle-essai',
            'broadcasting.connections.reverb.secret' => 'secret-essai',
            'broadcasting.connections.reverb.app_id' => '1001',
        ]);
        require base_path('routes/channels.php');
        $socket = ['socket_id' => '1234.5678'];
        $this->postJson('/api/v1/broadcasting/auth', [...$socket, 'channel_name' => 'private-utilisateur.'.$this->userVeto->id], $this->enTetes($this->veto))->assertOk();
        $this->postJson('/api/v1/broadcasting/auth', [...$socket, 'channel_name' => 'private-utilisateur.'.$this->employeAccueil->user_id], $this->enTetes($this->veto))->assertForbidden();
    }

    /** Décision de Jim : tout employé avec le droit Planning mène l'appel ; l'accueil (ASV, pas vétérinaire) se relie au client */
    public function test_l_accueil_avec_le_droit_planning_mene_l_appel(): void
    {
        $this->assertFalse($this->employeAccueil->estVeterinaire());

        $this->postJson($this->url('signal'), ['type' => 'pret', 'donnees' => []], $this->enTetes($this->accueil))->assertStatus(202);
        $this->getJson($this->url('ice'), $this->enTetes($this->accueil))->assertOk()->assertJsonStructure(['data' => ['ice_servers']]);

        // L'autorisation du canal seulement (rien n'est diffusé après)
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'cle-essai',
            'broadcasting.connections.reverb.secret' => 'secret-essai',
            'broadcasting.connections.reverb.app_id' => '1001',
        ]);
        require base_path('routes/channels.php');
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-visio.'.$this->session->id], $this->enTetes($this->accueil))->assertOk();
    }
}
