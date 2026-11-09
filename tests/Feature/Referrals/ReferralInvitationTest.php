<?php

namespace Tests\Feature\Referrals;

use App\Auth\ContexteClinique;
use App\Auth\EmetteurJetons;
use App\Enums\Espece;
use App\Mail\ReferralInvitationMail;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\Employe;
use App\Models\Patient;
use App\Models\ReferralInvitation;
use App\Models\ReferralLetter;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Referrals\ReferralLetterWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\ReferralLetterWriterFake;
use Tests\TestCase;

/**
 * La lettre de référence envoyée dans Veterinary, envoi C (choix de Jim,
 * 09.11.2026) : le confrère choisi dans l'annuaire ou par son e-mail, le
 * vétérinaire seul ou toute sa clinique ; l'invitation dans ses messages et
 * par e-mail ; rien de visible avant d'accepter ; accepter, puis choisir ou
 * créer l'animal, qui devient un patient « référé » ; la lettre et les
 * pièces se consultent sans copie jusqu'à ce que la clinique qui réfère
 * coupe l'accès ; refuser ; où en est la lettre, côté clinique qui réfère.
 */
class ReferralInvitationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private User $meyer;

    private Patient $patient;

    private RendezVous $consultation;

    private Clinique $bern;

    private User $keller;

    private string $kellerJeton;

    private string $assistantBern;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 14:00', 'Europe/Zurich'));
        Storage::fake('prive');
        Mail::fake();
        $this->app->instance(ReferralLetterWriter::class, new ReferralLetterWriterFake);

        // La clinique qui réfère
        $this->clinique = Clinique::factory()->create(['nom' => 'Clinique des Deux Rives']);
        ['user' => $this->meyer, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anna Meyer']);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'prenom' => 'Marc', 'nom' => 'Favre', 'tel' => '079 555 12 34', 'email' => 'marc.favre@example.ch']);
        $this->patient = Patient::factory()->for($client)->create(['nom' => 'Rocky', 'espece' => Espece::Chien, 'race' => 'Berger allemand', 'puce' => '756098123456789']);
        $this->consultation = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'reason' => 'Boiterie', 'starts_at' => now()->subDays(7), 'ends_at' => now()->subDays(7)->addMinutes(20)]);
        CompteRendu::factory()->create(['rendez_vous_id' => $this->consultation->id, 'signe_le' => now()->subDays(7), 's' => 'Boiterie postérieure gauche.', 'o' => 'Tiroir positif.', 'a' => '', 'p' => '']);

        // Le confrère, dans une autre organisation, et une assistante de sa clinique
        $this->bern = Clinique::factory()->create(['nom' => 'Vetsuisse Bern', 'ville' => 'Bern', 'email' => 'accueil@vetsuisse.ch', 'affiche_veterinaires' => true]);
        ['user' => $this->keller, 'jeton' => $this->kellerJeton] = $this->employeAvecJeton($this->bern, ['nom' => 'Dr Marc Keller']);
        $this->keller->forceFill(['email' => 'm.keller@vetsuisse.ch'])->save();
        ['jeton' => $this->assistantBern] = $this->employeAvecJeton($this->bern, ['nom' => 'Lea Huber'], 'assistant');

        app(ContexteClinique::class)->oublier();
    }

    private function signedLetter(): ReferralLetter
    {
        $id = $this->postJson("/api/v1/patients/{$this->patient->id}/referral-letters", [
            'items' => [['type' => 'consultation', 'id' => $this->consultation->id]],
            'recipient_name' => 'Dr Marc Keller',
        ], $this->enTetes($this->jeton))->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/referral-letters/{$id}", ['sections' => ['reason' => 'Boiterie postérieure gauche.', 'request' => 'Avis orthopédique.']], $this->enTetes($this->jeton))->assertOk();
        $this->postJson("/api/v1/referral-letters/{$id}/sign", [], $this->enTetes($this->jeton))->assertOk();
        app(ContexteClinique::class)->oublier();

        return ReferralLetter::withoutGlobalScopes()->findOrFail($id);
    }

    private function send(ReferralLetter $letter, array $to): int
    {
        $id = $this->postJson("/api/v1/referral-letters/{$letter->id}/invitations", $to, $this->enTetes($this->jeton))->assertCreated()->json('data.id');
        app(ContexteClinique::class)->oublier();

        return $id;
    }

    private function as(string $jeton, string $method, string $uri, array $data = []): TestResponse
    {
        app(ContexteClinique::class)->oublier();
        $response = $this->json($method, $uri, $data, $this->enTetes($jeton));
        app(ContexteClinique::class)->oublier();

        return $response;
    }

    public function test_l_annuaire_montre_les_cliniques_ouvertes_et_leurs_veterinaires_si_elles_les_affichent(): void
    {
        $hidden = Clinique::factory()->create(['nom' => 'Clinique du Lac Bern', 'ville' => 'Bern', 'affiche_veterinaires' => false]);
        $this->employeAvecJeton($hidden, ['nom' => 'Dre Sara Roth']);
        app(ContexteClinique::class)->oublier();

        $data = $this->as($this->jeton, 'GET', '/api/v1/referral-directory?q=Bern')->assertOk()->json('data');
        $byName = collect($data)->keyBy('name');
        $this->assertSame(['Dr Marc Keller'], array_column($byName['Vetsuisse Bern']['vets'], 'name'));
        $this->assertSame($this->keller->id, $byName['Vetsuisse Bern']['vets'][0]['user_id']);
        $this->assertSame([], $byName['Clinique du Lac Bern']['vets']);
        // Par le nom du vétérinaire aussi
        $this->assertSame(['Vetsuisse Bern'], array_column($this->as($this->jeton, 'GET', '/api/v1/referral-directory?q=Keller')->json('data'), 'name'));
    }

    public function test_envoi_c_a_un_veterinaire_de_l_annuaire_ou_par_son_e_mail_dans_ses_messages_et_par_e_mail(): void
    {
        $letter = $this->signedLetter();
        $id = $this->send($letter, ['kind' => 'vet', 'user_id' => $this->keller->id]);
        Mail::assertSent(ReferralInvitationMail::class, fn (ReferralInvitationMail $m) => $m->hasTo('m.keller@vetsuisse.ch'));
        $this->as($this->kellerJeton, 'GET', '/api/v1/referral-invitations')->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.from.clinic', 'Clinique des Deux Rives')
            ->assertJsonPath('data.0.from.by', 'Dre Anna Meyer')
            ->assertJsonPath('data.0.animal.name', 'Rocky');
        // Le même destinataire n'est pas invité deux fois
        $this->as($this->jeton, 'POST', "/api/v1/referral-letters/{$letter->id}/invitations", ['kind' => 'vet', 'email' => 'M.Keller@vetsuisse.ch'])
            ->assertUnprocessable()->assertJsonPath('errors.recipient.0', 'Ce destinataire a déjà la lettre.');
        // Une adresse sans vétérinaire sur Veterinary : le lien (envoi B)
        $this->as($this->jeton, 'POST', "/api/v1/referral-letters/{$letter->id}/invitations", ['kind' => 'vet', 'email' => 'inconnu@example.ch'])
            ->assertUnprocessable()->assertJsonPath('errors.recipient.0', 'Aucun vétérinaire sur Veterinary avec cette adresse : envoyez-lui le lien.');
    }

    public function test_seulement_une_lettre_signee_part(): void
    {
        $id = $this->postJson("/api/v1/patients/{$this->patient->id}/referral-letters", ['items' => []], $this->enTetes($this->jeton))->json('data.id');
        $this->as($this->jeton, 'POST', "/api/v1/referral-letters/{$id}/invitations", ['kind' => 'vet', 'user_id' => $this->keller->id])->assertUnprocessable();
    }

    public function test_celui_qui_envoie_choisit_le_veterinaire_seul_ou_toute_sa_clinique(): void
    {
        $letter = $this->signedLetter();
        $this->send($letter, ['kind' => 'vet', 'user_id' => $this->keller->id]);
        // Au vétérinaire seul : son assistante ne voit rien
        $this->as($this->assistantBern, 'GET', '/api/v1/referral-invitations')->assertOk()->assertJsonCount(0, 'data');

        $other = $this->signedLetter();
        $clinicId = $this->send($other, ['kind' => 'clinic', 'clinique_id' => $this->bern->id]);
        Mail::assertSent(ReferralInvitationMail::class, fn (ReferralInvitationMail $m) => $m->hasTo('accueil@vetsuisse.ch'));
        // À toute la clinique : l'assistante la voit et peut l'accepter
        $this->as($this->assistantBern, 'GET', '/api/v1/referral-invitations')->assertJsonPath('data.0.id', $clinicId);
        $this->as($this->assistantBern, 'POST', "/api/v1/referral-invitations/{$clinicId}/accept")->assertOk();
    }

    public function test_la_lettre_et_les_pieces_ne_sont_visibles_qu_une_fois_l_invitation_acceptee(): void
    {
        $letter = $this->signedLetter();
        $id = $this->send($letter, ['kind' => 'vet', 'user_id' => $this->keller->id]);
        $shown = $this->as($this->kellerJeton, 'GET', "/api/v1/referral-invitations/{$id}")->assertOk();
        $this->assertNull($shown->json('data.prefill'));
        $this->assertStringNotContainsString('Boiterie', $shown->getContent());
        $this->as($this->kellerJeton, 'GET', "/api/v1/referral-invitations/{$id}/items/referral_letter/{$letter->id}")->assertNotFound();
        $this->as($this->kellerJeton, 'GET', "/api/v1/referral-invitations/{$id}/items/consultation/{$this->consultation->id}")->assertNotFound();
    }

    public function test_en_acceptant_il_choisit_l_animal_ou_le_cree_preremplit_et_il_devient_un_patient_refere(): void
    {
        $letter = $this->signedLetter();
        $id = $this->send($letter, ['kind' => 'vet', 'user_id' => $this->keller->id]);
        $this->as($this->kellerJeton, 'POST', "/api/v1/referral-invitations/{$id}/open")->assertOk();
        $accepted = $this->as($this->kellerJeton, 'POST', "/api/v1/referral-invitations/{$id}/accept")->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.prefill.patient.name', 'Rocky')
            ->assertJsonPath('data.prefill.patient.chip', '756098123456789')
            ->assertJsonPath('data.prefill.owner.last_name', 'Favre')
            ->assertJsonPath('data.prefill.owner.phone', '079 555 12 34');
        $this->assertSame([], $accepted->json('data.prefill.matches'));
        // Toujours dans ses invitations tant que l'animal n'est pas choisi
        $this->as($this->kellerJeton, 'GET', '/api/v1/referral-invitations')->assertJsonPath('data.0.id', $id);

        // L'animal créé chez lui par le formulaire habituel, puis lié
        $owner = Client::factory()->create(['organisation_id' => $this->bern->societe->organisation_id, 'nom' => 'Favre']);
        $rocky = Patient::factory()->for($owner)->create(['nom' => 'Rocky']);
        app(ContexteClinique::class)->oublier();
        // Un animal d'une autre organisation : refusé
        $this->as($this->kellerJeton, 'POST', "/api/v1/referral-invitations/{$id}/link", ['patient_id' => $this->patient->id])->assertNotFound();
        $this->as($this->kellerJeton, 'POST', "/api/v1/referral-invitations/{$id}/link", ['patient_id' => $rocky->id])->assertOk()->assertJsonPath('data.target_patient_id', $rocky->id);
        $this->as($this->kellerJeton, 'GET', '/api/v1/referral-invitations')->assertJsonCount(0, 'data');

        // « Patients » : le filtre « Référés »
        $ids = array_column($this->as($this->kellerJeton, 'GET', '/api/v1/patients?filtre=referes')->assertOk()->json('data'), 'id');
        $this->assertSame([$rocky->id], $ids);
    }

    public function test_la_lettre_et_ses_pieces_se_consultent_depuis_le_dossier_de_cet_animal_sans_copie(): void
    {
        [$id, $rocky, $letter] = $this->acceptedAndLinked();
        $list = $this->as($this->kellerJeton, 'GET', "/api/v1/patients/{$rocky->id}/referrals")->assertOk()->json('data.0');
        $this->assertSame('Clinique des Deux Rives', $list['from']['clinic']);
        $this->assertSame(['referral_letter', 'consultation'], array_column($list['items'], 'type'));
        $this->as($this->kellerJeton, 'GET', "/api/v1/referral-invitations/{$id}/items/referral_letter/{$letter->id}")->assertOk()
            ->assertJsonPath('data.title', 'Lettre de référence à Dr Marc Keller');
        $this->as($this->kellerJeton, 'GET', "/api/v1/referral-invitations/{$id}/items/consultation/{$this->consultation->id}")->assertOk();
        // Une pièce qui n'est pas dans la lettre : introuvable
        $other = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addMinutes(20)]);
        CompteRendu::factory()->create(['rendez_vous_id' => $other->id, 'signe_le' => now()]);
        app(ContexteClinique::class)->oublier();
        $this->as($this->kellerJeton, 'GET', "/api/v1/referral-invitations/{$id}/items/consultation/{$other->id}")->assertNotFound();
        // Rien n'est copié dans son dossier
        $this->assertSame(0, $rocky->documents()->count());
    }

    public function test_l_acces_dure_jusqu_a_ce_que_la_clinique_qui_refere_le_coupe(): void
    {
        [$id, $rocky, $letter] = $this->acceptedAndLinked();
        // Trois mois plus tard (jetons renouvelés), l'accès est toujours là
        foreach ([$this->clinique, $this->bern] as $c) {
            $c->societe->organisation->forceFill(['periode_fin' => now()->addYear()])->save();
        }
        $this->travel(90)->days();
        $this->kellerJeton = app(EmetteurJetons::class)->emettre($this->keller)['jeton']->plainTextToken;
        $this->jeton = app(EmetteurJetons::class)->emettre($this->meyer)['jeton']->plainTextToken;
        $this->as($this->kellerJeton, 'GET', "/api/v1/referral-invitations/{$id}/items/referral_letter/{$letter->id}")->assertOk();
        // Une autre organisation ne coupe rien
        $this->as($this->kellerJeton, 'POST', "/api/v1/referral-invitations/{$id}/revoke")->assertNotFound();
        $this->as($this->jeton, 'POST', "/api/v1/referral-invitations/{$id}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->as($this->kellerJeton, 'GET', "/api/v1/referral-invitations/{$id}/items/referral_letter/{$letter->id}")->assertNotFound();
        $this->as($this->kellerJeton, 'GET', "/api/v1/patients/{$rocky->id}/referrals")->assertJsonPath('data.0.status', 'revoked')->assertJsonPath('data.0.items', []);
    }

    public function test_s_il_refuse_rien_n_est_partage_et_la_clinique_qui_refere_le_voit(): void
    {
        $letter = $this->signedLetter();
        $id = $this->send($letter, ['kind' => 'vet', 'user_id' => $this->keller->id]);
        $this->as($this->kellerJeton, 'POST', "/api/v1/referral-invitations/{$id}/refuse", ['reason' => 'Pas de place avant décembre.'])->assertOk()->assertJsonPath('data.status', 'refused');
        $this->as($this->kellerJeton, 'POST', "/api/v1/referral-invitations/{$id}/accept")->assertUnprocessable();
        $this->as($this->kellerJeton, 'GET', "/api/v1/referral-invitations/{$id}/items/referral_letter/{$letter->id}")->assertNotFound();
        $this->as($this->jeton, 'GET', "/api/v1/referral-letters/{$letter->id}")->assertOk()
            ->assertJsonPath('data.invitations.0.status', 'refused')
            ->assertJsonPath('data.invitations.0.refused_by_name', 'Dr Marc Keller')
            ->assertJsonPath('data.invitations.0.refusal_reason', 'Pas de place avant décembre.');
    }

    public function test_la_clinique_qui_refere_voit_ou_en_est_sa_lettre(): void
    {
        $letter = $this->signedLetter();
        $id = $this->send($letter, ['kind' => 'vet', 'user_id' => $this->keller->id]);
        $path = fn () => $this->as($this->jeton, 'GET', "/api/v1/referral-letters/{$letter->id}")->json('data.invitations.0');
        $this->assertSame(['pending', 'Dr Marc Keller', null], [$path()['status'], $path()['recipient_name'], $path()['opened_at']]);
        $this->as($this->kellerJeton, 'POST', "/api/v1/referral-invitations/{$id}/open")->assertOk();
        $this->assertNotNull($path()['opened_at']);
        $this->as($this->kellerJeton, 'POST', "/api/v1/referral-invitations/{$id}/accept")->assertOk();
        $this->assertSame(['accepted', 'Dr Marc Keller', 'Vetsuisse Bern'], [$path()['status'], $path()['accepted_by_name'], $path()['target_clinic']]);
    }

    public function test_un_autre_compte_ne_voit_ni_ne_repond(): void
    {
        $letter = $this->signedLetter();
        $id = $this->send($letter, ['kind' => 'vet', 'user_id' => $this->keller->id]);
        $stranger = Clinique::factory()->create();
        ['jeton' => $strangerJeton] = $this->employeAvecJeton($stranger);
        $this->as($strangerJeton, 'GET', '/api/v1/referral-invitations')->assertJsonCount(0, 'data');
        $this->as($strangerJeton, 'GET', "/api/v1/referral-invitations/{$id}")->assertNotFound();
        $this->as($strangerJeton, 'POST', "/api/v1/referral-invitations/{$id}/accept")->assertNotFound();
        $this->as($strangerJeton, 'POST', "/api/v1/referral-invitations/{$id}/refuse")->assertNotFound();
        $this->assertSame('pending', ReferralInvitation::query()->findOrFail($id)->status);
    }

    public function test_verification_l_annuaire_ne_dit_rien_d_un_veterinaire_qu_une_clinique_cache(): void
    {
        $hidden = Clinique::factory()->create(['nom' => 'Clinique du Lac', 'affiche_veterinaires' => false]);
        ['user' => $roth] = $this->employeAvecJeton($hidden, ['nom' => 'Dre Sara Roth']);
        app(ContexteClinique::class)->oublier();
        $this->as($this->jeton, 'GET', '/api/v1/referral-directory?q=Roth')->assertOk()->assertJsonCount(0, 'data');
        // Ni par son compte : seulement par son e-mail (on le connaît déjà), ou en invitant sa clinique
        $letter = $this->signedLetter();
        $this->as($this->jeton, 'POST', "/api/v1/referral-letters/{$letter->id}/invitations", ['kind' => 'vet', 'user_id' => $roth->id])
            ->assertUnprocessable()->assertJsonPath('errors.recipient.0', 'Ce vétérinaire n’est pas dans l’annuaire.');
        $roth->forceFill(['email' => 's.roth@lac.ch'])->save();
        $this->as($this->jeton, 'POST', "/api/v1/referral-letters/{$letter->id}/invitations", ['kind' => 'vet', 'email' => 's.roth@lac.ch'])->assertCreated();
    }

    public function test_verification_pas_d_invitation_vers_une_clinique_fermee_ni_vers_sa_propre_organisation(): void
    {
        $closed = Clinique::factory()->create(['affiche_veterinaires' => true]);
        $closed->societe->organisation->forceFill(['statut_abonnement' => null, 'periode_fin' => null])->save();
        ['user' => $ferme] = $this->employeAvecJeton($closed, ['nom' => 'Dr Fermé']);
        ['user' => $collegue] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Collègue']);
        app(ContexteClinique::class)->oublier();
        $letter = $this->signedLetter();
        $this->as($this->jeton, 'POST', "/api/v1/referral-letters/{$letter->id}/invitations", ['kind' => 'vet', 'user_id' => $ferme->id])->assertUnprocessable();
        $this->as($this->jeton, 'POST', "/api/v1/referral-letters/{$letter->id}/invitations", ['kind' => 'vet', 'user_id' => $collegue->id])
            ->assertUnprocessable()->assertJsonPath('errors.recipient.0', 'C’est votre organisation : le dossier y est déjà commun. Choisissez une autre clinique ou un confrère.');
        $this->as($this->jeton, 'POST', "/api/v1/referral-letters/{$letter->id}/invitations", ['kind' => 'vet', 'user_id' => $this->meyer->id])->assertUnprocessable();
        $this->assertSame(0, ReferralInvitation::query()->count());
    }

    public function test_deuxieme_verification_e_mail_d_une_clinique_fermee_et_clinique_de_sa_propre_organisation(): void
    {
        $closed = Clinique::factory()->create();
        $closed->societe->organisation->forceFill(['statut_abonnement' => null, 'periode_fin' => null])->save();
        ['user' => $ferme] = $this->employeAvecJeton($closed, ['nom' => 'Dr Fermé']);
        $ferme->forceFill(['email' => 'ferme@example.ch'])->save();
        $sister = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id, 'nom' => 'Deux Rives Est']);
        app(ContexteClinique::class)->oublier();
        $letter = $this->signedLetter();
        $this->as($this->jeton, 'POST', "/api/v1/referral-letters/{$letter->id}/invitations", ['kind' => 'vet', 'email' => 'ferme@example.ch'])
            ->assertUnprocessable()->assertJsonPath('errors.recipient.0', 'Aucun vétérinaire sur Veterinary avec cette adresse : envoyez-lui le lien.');
        $this->as($this->jeton, 'POST', "/api/v1/referral-letters/{$letter->id}/invitations", ['kind' => 'clinic', 'clinique_id' => $sister->id])
            ->assertUnprocessable()->assertJsonPath('errors.recipient.0', 'C’est votre organisation : le dossier y est déjà commun. Choisissez une autre clinique ou un confrère.');
        // L'annuaire ne propose pas ses propres cliniques
        $this->assertNotContains('Deux Rives Est', array_column($this->as($this->jeton, 'GET', '/api/v1/referral-directory?q=Deux Rives')->json('data'), 'name'));
    }

    public function test_deuxieme_verification_un_confrere_qui_travaille_aussi_ici_recoit_la_lettre_dans_son_autre_clinique(): void
    {
        // Le Dr Keller est aussi vétérinaire à Deux Rives, l'organisation qui réfère
        Employe::factory()->dans($this->clinique)->create(['user_id' => $this->keller->id, 'nom' => 'Dr Marc Keller']);
        app(ContexteClinique::class)->oublier();
        $bernJeton = app(EmetteurJetons::class)->emettre($this->keller, $this->bern->id)['jeton']->plainTextToken;
        $hereJeton = app(EmetteurJetons::class)->emettre($this->keller, $this->clinique->id)['jeton']->plainTextToken;
        $letter = $this->signedLetter();
        $id = $this->send($letter, ['kind' => 'vet', 'user_id' => $this->keller->id]);
        // Pas depuis l'organisation qui réfère ; depuis Bern, oui
        $this->as($hereJeton, 'POST', "/api/v1/referral-invitations/{$id}/accept")->assertNotFound();
        $this->as($bernJeton, 'POST', "/api/v1/referral-invitations/{$id}/accept")->assertOk();
    }

    public function test_verification_une_invitation_coupee_ne_se_marque_plus_lue(): void
    {
        $letter = $this->signedLetter();
        $id = $this->send($letter, ['kind' => 'vet', 'user_id' => $this->keller->id]);
        $this->as($this->jeton, 'POST', "/api/v1/referral-invitations/{$id}/revoke")->assertOk();
        $this->as($this->kellerJeton, 'POST', "/api/v1/referral-invitations/{$id}/open");
        $this->assertNull(ReferralInvitation::query()->findOrFail($id)->opened_at);
    }

    public function test_verification_l_animal_se_lie_depuis_la_clinique_qui_a_accepte(): void
    {
        // Le même vétérinaire travaille aussi dans une autre organisation
        $other = Clinique::factory()->create();
        Employe::factory()->dans($other)->create(['user_id' => $this->keller->id, 'nom' => 'Dr Marc Keller']);
        app(ContexteClinique::class)->oublier();
        $bernJeton = app(EmetteurJetons::class)->emettre($this->keller, $this->bern->id)['jeton']->plainTextToken;
        $otherJeton = app(EmetteurJetons::class)->emettre($this->keller, $other->id)['jeton']->plainTextToken;
        $letter = $this->signedLetter();
        $id = $this->send($letter, ['kind' => 'vet', 'user_id' => $this->keller->id]);
        $this->as($bernJeton, 'POST', "/api/v1/referral-invitations/{$id}/accept")->assertOk();
        $owner = Client::factory()->create(['organisation_id' => $other->societe->organisation_id]);
        $rocky = Patient::factory()->for($owner)->create(['nom' => 'Rocky']);
        app(ContexteClinique::class)->oublier();
        $this->as($otherJeton, 'POST', "/api/v1/referral-invitations/{$id}/link", ['patient_id' => $rocky->id])->assertNotFound();
    }

    public function test_verification_rien_de_medical_dans_l_e_mail(): void
    {
        $letter = $this->signedLetter();
        $this->send($letter, ['kind' => 'vet', 'user_id' => $this->keller->id]);
        Mail::assertSent(ReferralInvitationMail::class, function (ReferralInvitationMail $m) {
            $html = $m->render();
            $this->assertStringContainsString('Rocky', $html);
            $this->assertStringNotContainsString('Boiterie', $html);
            $this->assertStringNotContainsString('orthopédique', $html);

            return true;
        });
    }

    /** @return array{0: int, 1: Patient, 2: ReferralLetter} */
    private function acceptedAndLinked(): array
    {
        $letter = $this->signedLetter();
        $id = $this->send($letter, ['kind' => 'vet', 'user_id' => $this->keller->id]);
        $this->as($this->kellerJeton, 'POST', "/api/v1/referral-invitations/{$id}/accept")->assertOk();
        $owner = Client::factory()->create(['organisation_id' => $this->bern->societe->organisation_id, 'nom' => 'Favre']);
        $rocky = Patient::factory()->for($owner)->create(['nom' => 'Rocky']);
        $this->as($this->kellerJeton, 'POST', "/api/v1/referral-invitations/{$id}/link", ['patient_id' => $rocky->id])->assertOk();

        return [$id, $rocky, $letter];
    }
}
