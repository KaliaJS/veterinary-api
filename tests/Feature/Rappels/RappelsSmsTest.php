<?php

namespace Tests\Feature\Rappels;

use App\Enums\FormuleSaas;
use App\Enums\TypeRendezVous;
use App\Mail\RappelVaccin;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\PreferencesProprietaire;
use App\Models\RappelEnvoye;
use App\Models\RendezVous;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\CompteurMensuel;
use App\Support\TexteSms;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\FauxTwilio;
use Tests\TestCase;

/**
 * Rappels par SMS (décisions de Jim, 28.10.2026) : le client choisit SMS
 * et/ou e-mail ; la clinique décide si elle utilise les SMS ; un client
 * sans compte reçoit des SMS si la clinique coche la case sur sa fiche.
 * Les SMS partent par Twilio sous le nom « Veterinary » et comptent dans
 * le quota du mois de l'organisation.
 */
class RappelsSmsTest extends TestCase
{
    use EmetDesJetonsApi;
    use FauxTwilio;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Client $client;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fauxTwilio();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives', 'tel' => '032 000 00 00']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
        $organisation = $this->clinique->societe->organisation_id;
        $this->client = Client::factory()->create(['organisation_id' => $organisation, 'prenom' => 'Léa', 'nom' => 'Martin', 'email' => 'lea.martin@example.ch', 'tel' => '079 123 45 67']);
        $this->patient = Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $this->client->id, 'nom' => 'Félix']);
        RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subMonths(11), 'ends_at' => now()->subMonths(11)->addMinutes(20)]);
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Rage', 'injectee_le' => '2025-11-12', 'echeance_le' => '2026-11-12']);
    }

    private function smsActifs(): void
    {
        $this->putJson('/api/v1/rappels/reglages', ['sms' => true], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.sms', true);
    }

    private function envoyer(): void
    {
        $this->artisan('rappels:envoyer')->assertSuccessful();
    }

    /** @return list<array<string, mixed>> */
    private function smsEnvoyes(): array
    {
        return array_values(array_filter($this->requetesTwilio, fn (array $r): bool => str_ends_with($r['url'], '/Messages.json')));
    }

    public function test_la_clinique_decide_si_elle_utilise_les_sms(): void
    {
        $this->getJson('/api/v1/rappels/reglages', $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.sms', false)->assertJsonPath('data.sms_disponible', true);

        $this->smsActifs();
    }

    public function test_sans_cle_twilio_la_clinique_ne_peut_pas_activer_les_sms(): void
    {
        config(['twilio-notification-channel.password' => null]);

        $this->getJson('/api/v1/rappels/reglages', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.sms_disponible', false);
        $this->putJson('/api/v1/rappels/reglages', ['sms' => true], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('sms');
    }

    public function test_un_client_sans_compte_recoit_un_sms_quand_la_clinique_coche_la_case_sur_sa_fiche(): void
    {
        $this->smsActifs();
        $this->putJson("/api/v1/clients/{$this->client->id}", ['rappels_sms' => true], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.rappels_sms', true);

        $this->envoyer();

        $sms = $this->smsEnvoyes();
        $this->assertCount(1, $sms);
        $this->assertSame('+41791234567', $sms[0]['data']['To']);
        $this->assertSame('Veterinary', $sms[0]['data']['From']);
        $this->assertStringContainsString('Deux Rives', $sms[0]['data']['Body']);
        $this->assertStringContainsString('Félix', $sms[0]['data']['Body']);
        $this->assertStringContainsString('Rage', $sms[0]['data']['Body']);
        $this->assertStringContainsString('12.11.2026', $sms[0]['data']['Body']);
        $this->assertStringContainsString('032 000 00 00', $sms[0]['data']['Body']);
        $this->assertLessThanOrEqual(160, mb_strlen($sms[0]['data']['Body']));
        // L'e-mail part aussi : la case ajoute le SMS
        Mail::assertSent(RappelVaccin::class, 1);
        $this->assertSame(['email', 'sms'], RappelEnvoye::withoutGlobalScopes()->orderBy('canal')->pluck('canal')->all());
    }

    public function test_sans_la_case_un_client_sans_compte_ne_recoit_que_l_e_mail(): void
    {
        $this->smsActifs();

        $this->envoyer();

        $this->assertSame([], $this->smsEnvoyes());
        Mail::assertSent(RappelVaccin::class, 1);
    }

    public function test_un_client_avec_compte_recoit_par_les_canaux_qu_il_a_choisis(): void
    {
        $this->smsActifs();
        $user = User::factory()->create();
        $this->client->update(['user_id' => $user->id]);
        PreferencesProprietaire::pour($user)->update(['rappels_email' => false, 'rappels_sms' => true]);

        $this->envoyer();

        $this->assertCount(1, $this->smsEnvoyes());
        Mail::assertNothingSent();
    }

    public function test_la_clinique_sans_sms_n_en_envoie_aucun_meme_si_le_client_les_veut(): void
    {
        $this->client->update(['rappels_sms' => true]);

        $this->envoyer();

        $this->assertSame([], $this->smsEnvoyes());
        Mail::assertSent(RappelVaccin::class, 1);
    }

    public function test_un_numero_fixe_ne_recoit_pas_de_sms(): void
    {
        $this->smsActifs();
        $this->client->update(['rappels_sms' => true, 'tel' => '032 123 45 67']);

        $this->envoyer();

        $this->assertSame([], $this->smsEnvoyes());
    }

    public function test_le_sms_part_une_seule_fois_par_echeance(): void
    {
        $this->smsActifs();
        $this->client->update(['rappels_sms' => true]);

        $this->envoyer();
        $this->envoyer();

        $this->assertCount(1, $this->smsEnvoyes());
    }

    public function test_chaque_sms_compte_dans_le_quota_du_mois_et_le_quota_atteint_bloque_le_sms(): void
    {
        $this->smsActifs();
        $this->client->update(['rappels_sms' => true]);
        $organisation = $this->clinique->societe->organisation;
        $organisation->forceFill(['plan' => FormuleSaas::Individuel, 'quota_extra_sms' => 0])->save();
        CompteurMensuel::ajouter('sms:'.$organisation->id, 100);

        $this->envoyer();

        $this->assertSame([], $this->smsEnvoyes());
        $bloque = RappelEnvoye::withoutGlobalScopes()->where('canal', 'sms')->sole();
        $this->assertSame('echec', $bloque->statut);
        $this->assertStringContainsString('quota', mb_strtolower((string) $bloque->erreur));
        // L'e-mail, lui, part
        Mail::assertSent(RappelVaccin::class, 1);
    }

    public function test_un_sms_parti_compte_dans_l_usage_du_mois_servi(): void
    {
        $this->smsActifs();
        $this->client->update(['rappels_sms' => true]);

        $this->envoyer();

        $this->getJson('/api/v1/organisation', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.usage.sms', 1);
    }

    public function test_un_refus_de_twilio_est_garde_comme_echec_sans_bloquer_l_e_mail(): void
    {
        $this->smsActifs();
        $this->client->update(['rappels_sms' => true]);
        $this->fauxTwilio(400, ['code' => 21614, 'message' => "'To' number is not a valid mobile number", 'status' => 400]);

        $this->envoyer();

        $rappel = RappelEnvoye::withoutGlobalScopes()->where('canal', 'sms')->sole();
        $this->assertSame('echec', $rappel->statut);
        $this->assertStringContainsString('not a valid mobile number', (string) $rappel->erreur);
        Mail::assertSent(RappelVaccin::class, 1);
        // Un SMS refusé ne compte pas dans le quota
        $this->assertSame(0, CompteurMensuel::valeur('sms:'.$this->clinique->societe->organisation_id));
    }

    /** La case se coche aussi à la création de la fiche */
    public function test_la_case_sms_se_coche_a_la_creation_de_la_fiche(): void
    {
        $this->postJson('/api/v1/clients', ['prenom' => 'Pierre', 'nom' => 'Rochat', 'tel' => '079 765 43 21', 'rappels_sms' => true], $this->enTetes($this->jeton))
            ->assertCreated()->assertJsonPath('data.rappels_sms', true);
    }

    /** Le SMS d'un contrôle part dans l'alphabet SMS (un segment), et le quota compte les segments */
    public function test_le_sms_d_un_controle_tient_en_un_segment_et_le_quota_compte_les_segments(): void
    {
        $this->smsActifs();
        $this->client->update(['rappels_sms' => true]);
        RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'type' => TypeRendezVous::Controle,
            'starts_at' => CarbonImmutable::parse('2026-11-12 14:30', 'Europe/Zurich')->utc(), 'ends_at' => CarbonImmutable::parse('2026-11-12 14:50', 'Europe/Zurich')->utc()]);
        $this->clinique->update(['nom' => 'Clinique vétérinaire des Deux Rives de Neuchâtel']);

        $this->envoyer();

        $controle = collect($this->smsEnvoyes())->first(fn (array $r): bool => str_contains($r['data']['Body'], 'controle'));
        $this->assertNotNull($controle);
        $this->assertSame(1, TexteSms::segments($controle['data']['Body']));
        $this->assertStringContainsString('Neuchatel', $controle['data']['Body']);
        $total = collect($this->smsEnvoyes())->sum(fn (array $r): int => TexteSms::segments($r['data']['Body']));
        $this->assertSame($total, CompteurMensuel::valeur('sms:'.$this->clinique->societe->organisation_id));
    }

    /** Décision de Jim : « Rappeler le propriétaire » suit les canaux choisis par le client, SMS compris */
    public function test_rappeler_le_proprietaire_suit_ses_canaux_sms_compris(): void
    {
        $this->smsActifs();
        $user = User::factory()->create();
        $this->client->update(['user_id' => $user->id]);
        PreferencesProprietaire::pour($user)->update(['rappels_email' => false, 'rappels_sms' => true]);
        $vaccination = Vaccination::query()->where('patient_id', $this->patient->id)->sole();

        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$vaccination->id}/rappel", [], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.statut', 'envoye')->assertJsonPath('data.adresse', '+41791234567');

        $this->assertCount(1, $this->smsEnvoyes());
        Mail::assertNothingSent();
    }

    /** Respecter son choix (décision de Jim) : SMS seulement, mais la clinique n'en envoie pas : rien ne part, la raison est dite */
    public function test_sms_seulement_sans_sms_a_la_clinique_rien_ne_part_et_la_raison_est_dite(): void
    {
        $user = User::factory()->create();
        $this->client->update(['user_id' => $user->id]);
        PreferencesProprietaire::pour($user)->update(['rappels_email' => false, 'rappels_sms' => true]);
        $vaccination = Vaccination::query()->where('patient_id', $this->patient->id)->sole();

        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$vaccination->id}/rappel", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['rappel' => 'Léa Martin a choisi les SMS seulement, et la clinique n’en envoie pas.']);
        $this->envoyer();

        $this->assertSame([], $this->smsEnvoyes());
        Mail::assertNothingSent();
        // La fenêtre « À venir » de la clinique le dit
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Zurich'));
        $ligne = collect($this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->assertOk()->json('data.a_venir'))->firstWhere('patient.nom', 'Félix');
        $this->assertSame('SMS seulement, que la clinique n’envoie pas', $ligne['bloque']);
    }

    /** SMS coupés (TWILIO_ENABLED=false) : ils ne sont ni proposés ni comptés comme partis */
    public function test_sms_coupes_ils_ne_sont_ni_proposes_ni_comptes(): void
    {
        $this->smsActifs();
        $this->client->update(['rappels_sms' => true]);
        config(['twilio-notification-channel.enabled' => false]);

        $this->getJson('/api/v1/rappels/reglages', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.sms_disponible', false);
        $this->envoyer();

        $this->assertSame(0, RappelEnvoye::withoutGlobalScopes()->where('canal', 'sms')->count());
        $this->assertSame(0, CompteurMensuel::valeur('sms:'.$this->clinique->societe->organisation_id));
    }

    /** Le quota réserve les segments facturés : un SMS de deux segments ne part pas s'il n'en reste qu'un */
    public function test_un_sms_de_deux_segments_ne_part_pas_quand_il_n_en_reste_qu_un(): void
    {
        $this->smsActifs();
        $this->client->update(['rappels_sms' => true]);
        $organisation = $this->clinique->societe->organisation;
        $organisation->forceFill(['plan' => FormuleSaas::Individuel])->save();
        CompteurMensuel::ajouter('sms:'.$organisation->id, 99);
        // Un nom de clinique long : le SMS du vaccin fait deux segments
        $this->clinique->update(['nom' => str_repeat('Clinique vétérinaire des Deux Rives ', 3)]);

        $this->envoyer();

        $this->assertSame([], $this->smsEnvoyes());
        $this->assertStringContainsString('quota', mb_strtolower((string) RappelEnvoye::withoutGlobalScopes()->where('canal', 'sms')->sole()->erreur));
        $this->assertSame(99, CompteurMensuel::valeur('sms:'.$organisation->id));
    }

    /** « À venir » dit par où chaque rappel partira */
    public function test_a_venir_dit_par_ou_le_rappel_partira(): void
    {
        $this->smsActifs();
        $this->client->update(['rappels_sms' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Zurich'));

        $ligne = collect($this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->assertOk()->json('data.a_venir'))->firstWhere('patient.nom', 'Félix');

        $this->assertSame(['email', 'sms'], $ligne['canaux']);
    }

    /** « SMS seulement », la clinique envoie des SMS, mais la fiche porte un fixe : « À venir » dit qu'il manque le mobile, comme le carnet */
    public function test_sms_seulement_avec_un_fixe_a_venir_dit_qu_il_manque_le_mobile(): void
    {
        $this->smsActifs();
        $user = User::factory()->create();
        $this->client->update(['user_id' => $user->id, 'tel' => '032 123 45 67']);
        PreferencesProprietaire::pour($user)->update(['rappels_email' => false, 'rappels_sms' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Zurich'));

        $ligne = collect($this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->assertOk()->json('data.a_venir'))->firstWhere('patient.nom', 'Félix');

        $this->assertSame([], $ligne['canaux']);
        $this->assertSame('Pas de numéro de mobile', $ligne['bloque']);
    }

    /** « Rappeler le propriétaire » par e-mail et SMS : le SMS bloqué par le quota se dit, l'e-mail parti aussi */
    public function test_rappeler_le_proprietaire_dit_le_canal_qui_n_est_pas_parti(): void
    {
        $this->smsActifs();
        $this->client->update(['rappels_sms' => true]);
        $organisation = $this->clinique->societe->organisation;
        $organisation->forceFill(['plan' => FormuleSaas::Individuel, 'quota_extra_sms' => 0])->save();
        CompteurMensuel::ajouter('sms:'.$organisation->id, 100);
        $vaccination = Vaccination::query()->where('patient_id', $this->patient->id)->sole();

        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$vaccination->id}/rappel", [], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.adresse', 'lea.martin@example.ch')
            ->assertJsonPath('data.manque', 'Le SMS n’est pas parti, quota de SMS du mois atteint : achetez un pack dans l’abonnement.');
    }
}
