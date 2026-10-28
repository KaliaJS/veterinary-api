<?php

namespace Tests\Feature\Rappels;

use App\Enums\StatutRendezVous;
use App\Mail\RelanceNoShow;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\PreferencesProprietaire;
use App\Models\RendezVous;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\SiteWebFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\FauxTwilio;
use Tests\TestCase;

/**
 * « Relance après no-show » (décision de Jim, 28.10.2026) : après une
 * absence non excusée, le soir même, un message avec un lien pour
 * reprendre rendez-vous, par le canal choisi par le client.
 */
class RelanceNoShowTest extends TestCase
{
    use EmetDesJetonsApi;
    use FauxTwilio;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Client $client;

    private RendezVous $rendezVous;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fauxTwilio();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:30', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->dansLAnnuaire()->create(['nom' => 'Deux Rives', 'tel' => '032 000 00 00', 'fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
        $organisation = $this->clinique->societe->organisation_id;
        $this->client = Client::factory()->create(['organisation_id' => $organisation, 'prenom' => 'Léa', 'nom' => 'Martin', 'email' => 'lea.martin@example.ch', 'tel' => '079 123 45 67']);
        $patient = Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $this->client->id, 'nom' => 'Félix']);
        $this->rendezVous = RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subMinutes(30), 'ends_at' => now()]);
        $this->postJson("/api/v1/rendez-vous/{$this->rendezVous->id}/non-venu", [], $this->enTetes($this->jeton))->assertOk();
    }

    private function relanceActive(): void
    {
        $this->putJson('/api/v1/rappels/reglages', ['relance_noshow' => true], $this->enTetes($this->jeton))->assertOk();
    }

    private function soir(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 18:05', 'Europe/Zurich'));
        $this->artisan('rappels:envoyer')->assertSuccessful();
    }

    public function test_le_soir_meme_le_proprietaire_recoit_un_lien_pour_reprendre_rendez_vous(): void
    {
        $this->relanceActive();
        $this->assertSame(StatutRendezVous::Noshow, $this->rendezVous->refresh()->status);

        $this->soir();

        Mail::assertSent(RelanceNoShow::class, fn (RelanceNoShow $mail): bool => $mail->hasTo('lea.martin@example.ch')
            && str_contains($mail->render(), 'Félix')
            && str_contains($mail->render(), 'Deux Rives')
            && str_contains($mail->render(), rtrim((string) config('app.frontend_url'), '/').'/cliniques/'.$this->clinique->id));
    }

    public function test_pas_avant_le_soir(): void
    {
        $this->relanceActive();

        $this->artisan('rappels:envoyer')->assertSuccessful();

        Mail::assertNotSent(RelanceNoShow::class);
    }

    public function test_relance_coupee_rien_ne_part(): void
    {
        $this->soir();

        Mail::assertNotSent(RelanceNoShow::class);
    }

    public function test_une_seule_fois(): void
    {
        $this->relanceActive();

        $this->soir();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 19:05', 'Europe/Zurich'));
        $this->artisan('rappels:envoyer')->assertSuccessful();

        Mail::assertSent(RelanceNoShow::class, 1);
    }

    public function test_par_sms_quand_le_client_l_a_choisi(): void
    {
        $this->relanceActive();
        $this->putJson('/api/v1/rappels/reglages', ['sms' => true], $this->enTetes($this->jeton))->assertOk();
        $this->client->update(['rappels_sms' => true]);

        $this->soir();

        $sms = array_values(array_filter($this->requetesTwilio, fn (array $r): bool => str_ends_with($r['url'], '/Messages.json')));
        $this->assertCount(1, $sms);
        $this->assertStringContainsString('Félix', $sms[0]['data']['Body']);
        $this->assertStringContainsString('/cliniques/'.$this->clinique->id, $sms[0]['data']['Body']);
        $this->assertLessThanOrEqual(160, mb_strlen($sms[0]['data']['Body']));
    }

    public function test_un_rendez_vous_honore_n_est_pas_relance(): void
    {
        $this->relanceActive();
        $this->rendezVous->update(['status' => StatutRendezVous::Fini]);

        $this->soir();

        Mail::assertNotSent(RelanceNoShow::class);
    }

    /** Absence marquée tard (19 h 10) : la relance part au passage suivant du soir */
    public function test_une_absence_marquee_tard_part_au_passage_suivant_du_soir(): void
    {
        $this->relanceActive();

        $this->travelTo(CarbonImmutable::parse('2026-11-05 20:05', 'Europe/Zurich'));
        $this->artisan('rappels:envoyer')->assertSuccessful();

        Mail::assertSent(RelanceNoShow::class, 1);
    }

    /** Après 22 h, plus rien la nuit : la relance part le lendemain matin, une fois */
    public function test_marquee_dans_la_nuit_elle_part_le_lendemain_matin(): void
    {
        $this->relanceActive();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 23:05', 'Europe/Zurich'));
        $this->artisan('rappels:envoyer')->assertSuccessful();
        Mail::assertNotSent(RelanceNoShow::class);

        $this->travelTo(CarbonImmutable::parse('2026-11-06 08:05', 'Europe/Zurich'));
        $this->artisan('rappels:envoyer')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:05', 'Europe/Zurich'));
        $this->artisan('rappels:envoyer')->assertSuccessful();

        Mail::assertSent(RelanceNoShow::class, 1);
    }

    /** Une absence d'avant-hier, ou d'hier à midi passé : jamais relancée */
    public function test_une_absence_ancienne_n_est_pas_relancee(): void
    {
        $this->relanceActive();

        $this->travelTo(CarbonImmutable::parse('2026-11-06 13:05', 'Europe/Zurich'));
        $this->artisan('rappels:envoyer')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 18:05', 'Europe/Zurich'));
        $this->artisan('rappels:envoyer')->assertSuccessful();

        Mail::assertNotSent(RelanceNoShow::class);
    }

    /** Animal décédé, rappels coupés pour l'animal, organisation fermée : rien ne part */
    public function test_animal_decede_rappels_coupes_ou_organisation_fermee_rien_ne_part(): void
    {
        $this->relanceActive();
        $patient = $this->rendezVous->patient()->withoutGlobalScopes()->firstOrFail();

        $patient->forceFill(['decede_le' => now()->toDateString()])->save();
        $this->soir();
        $patient->forceFill(['decede_le' => null, 'rappels_coupes' => true])->save();
        $this->soir();
        $patient->forceFill(['rappels_coupes' => false])->save();
        $this->clinique->societe->organisation->forceFill(['statut_abonnement' => 'resilie'])->save();
        $this->soir();

        Mail::assertNotSent(RelanceNoShow::class);
    }

    /** « SMS seulement » : aucun e-mail de relance */
    public function test_sms_seulement_pas_d_e_mail_de_relance(): void
    {
        $this->relanceActive();
        $user = User::factory()->create();
        $this->client->update(['user_id' => $user->id]);
        PreferencesProprietaire::pour($user)->update(['rappels_email' => false, 'rappels_sms' => true]);

        $this->soir();

        Mail::assertNotSent(RelanceNoShow::class);
    }

    /** Sans site publié ni place dans l'annuaire, le lien mènerait nulle part : le message donne le téléphone */
    public function test_sans_page_publique_le_message_donne_le_telephone(): void
    {
        $this->relanceActive();
        $this->clinique->forceFill(['photo_chemin' => null])->save();

        $this->soir();

        Mail::assertSent(RelanceNoShow::class, fn (RelanceNoShow $mail): bool => $mail->lienReprise === null
            && str_contains($mail->render(), 'appelez la clinique au 032 000 00 00')
            && ! str_contains($mail->render(), '/cliniques/'));
    }

    /** Un rendez-vous déjà repris pour l'animal (par téléphone, l'après-midi) : pas de « Reprenez rendez-vous » le soir */
    public function test_un_rendez_vous_deja_repris_n_est_pas_relance(): void
    {
        $this->relanceActive();
        $patient = $this->rendezVous->patient()->withoutGlobalScopes()->firstOrFail();
        RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addMinutes(20)]);

        $this->soir();

        Mail::assertNotSent(RelanceNoShow::class);
    }

    /** Le rendez-vous repris puis annulé : la relance part quand même */
    public function test_un_rendez_vous_repris_puis_annule_n_empeche_pas_la_relance(): void
    {
        $this->relanceActive();
        $patient = $this->rendezVous->patient()->withoutGlobalScopes()->firstOrFail();
        RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addMinutes(20), 'status' => StatutRendezVous::Annule]);

        $this->soir();

        Mail::assertSent(RelanceNoShow::class, 1);
    }

    /** Rien ne part la nuit (entre 22 h et 8 h) ; le lendemain matin, oui ; le soir suivant, plus rien de nouveau */
    public function test_rien_ne_part_la_nuit_ni_le_soir_suivant(): void
    {
        $this->relanceActive();

        $this->travelTo(CarbonImmutable::parse('2026-11-06 03:05', 'Europe/Zurich'));
        $this->artisan('rappels:envoyer')->assertSuccessful();
        Mail::assertNotSent(RelanceNoShow::class);

        $this->travelTo(CarbonImmutable::parse('2026-11-06 18:05', 'Europe/Zurich'));
        $this->artisan('rappels:envoyer')->assertSuccessful();
        Mail::assertNotSent(RelanceNoShow::class);
    }

    /** Rappels par e-mail coupés par la clinique : pas d'e-mail de relance */
    public function test_rappels_par_e_mail_coupes_par_la_clinique_pas_d_e_mail_de_relance(): void
    {
        $this->relanceActive();
        $this->putJson('/api/v1/rappels/reglages', ['email' => false], $this->enTetes($this->jeton))->assertOk();

        $this->soir();

        Mail::assertNotSent(RelanceNoShow::class);
    }

    /** Un site publié : le lien mène au site de la clinique, avant l'annuaire */
    public function test_avec_un_site_publie_le_lien_mene_au_site(): void
    {
        $this->relanceActive();
        $site = SiteWebFactory::new()->publie()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'slug' => 'deux-rives']);

        $this->soir();

        Mail::assertSent(RelanceNoShow::class, fn (RelanceNoShow $mail): bool => $mail->lienReprise === $site->urlPublique()
            && str_contains($mail->render(), '/site/deux-rives'));
    }
}
