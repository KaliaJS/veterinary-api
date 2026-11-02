<?php

namespace Tests\Feature\Bloc;

use App\Auth\ContexteClinique;
use App\Enums\ConsentMethod;
use App\Enums\StatutIntervention;
use App\Events\InterventionUpdated;
use App\Mail\ConsentRequested;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\ConsentRequest;
use App\Models\Employe;
use App\Models\Intervention;
use App\Models\Patient;
use App\Models\Salle;
use App\Services\Bloc\ConsentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\FauxTwilio;
use Tests\TestCase;

/**
 * Le consentement éclairé envoyé pour signature ou recueilli sans
 * signature (demandes de Jim, 02.11.2026) : un lien personnel par SMS,
 * par e-mail ou dans l'espace du propriétaire, signé sur son téléphone ;
 * ou un accord oral, ou une urgence, sous la responsabilité du
 * vétérinaire. Le point `consent` de la checklist se coche dans tous les cas.
 */
class ConsentTest extends TestCase
{
    use EmetDesJetonsApi;
    use FauxTwilio;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Employe $veterinaire;

    private Client $client;

    private Intervention $intervention;

    /** Une image PNG de signature, 60 × 30 */
    private const string SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADwAAAAeCAIAAAD/I/+cAAAAIUlEQVR4nO3BAQ0AAADCoPdPbQ8HFAAAAAAAAAAAAAAA8GYmrgABnFG8PAAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('prive');
        $this->fauxTwilio();
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives', 'tel' => '032 000 00 00']);
        ['employe' => $this->veterinaire, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $organisation = $this->clinique->societe->organisation_id;
        $this->client = Client::factory()->create(['organisation_id' => $organisation, 'prenom' => 'Marc', 'nom' => 'Fournier', 'email' => 'm.fournier@example.ch', 'tel' => '079 123 45 67']);
        $patient = Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $this->client->id, 'nom' => 'Bagheera']);
        $salle = Salle::factory()->bloc()->create(['clinique_id' => $this->clinique->id]);
        $this->intervention = Intervention::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $patient->id,
            'salle_id' => $salle->id,
            'salle_nom' => $salle->nom,
            'chirurgien_id' => $this->veterinaire->id,
            'procedure' => 'Ovariohystérectomie',
        ]);
    }

    private function url(string $suite = ''): string
    {
        return '/api/v1/bloc/interventions/'.$this->intervention->id.'/consentement'.$suite;
    }

    private function envoyer(array $canaux): TestResponse
    {
        return $this->postJson($this->url('/envoi'), ['channels' => $canaux], $this->enTetes($this->jeton));
    }

    /** Le jeton du lien envoyé par e-mail */
    private function jetonEnvoye(): string
    {
        $lien = null;
        Mail::assertSent(ConsentRequested::class, function (ConsentRequested $mail) use (&$lien): bool {
            $lien = $mail->lien;

            return $mail->hasTo('m.fournier@example.ch');
        });
        preg_match('#/consentement/([A-Za-z0-9]{48})$#', (string) $lien, $m);

        return $m[1];
    }

    private function consentCoche(): bool
    {
        return (bool) $this->intervention->checklist()->where('code', 'consent')->value('est_coche');
    }

    public function test_envoye_par_e_mail_le_proprietaire_signe_par_le_lien_et_la_signature_revient(): void
    {
        Event::fake([InterventionUpdated::class]);
        $this->envoyer(['email'])->assertOk()
            ->assertJsonPath('data.consent_request.channels', ['email'])
            ->assertJsonPath('data.consentement_signe_le', null);
        $jeton = $this->jetonEnvoye();

        $this->getJson('/api/v1/public/consentements/'.$jeton)->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.patient.nom', 'Bagheera')
            ->assertJsonPath('data.procedure', 'Ovariohystérectomie')
            ->assertJsonPath('data.clinic.nom', 'Deux Rives');

        $this->postJson('/api/v1/public/consentements/'.$jeton.'/signer', ['signataire' => 'Marc Fournier', 'signature' => self::SIGNATURE])
            ->assertOk()->assertJsonPath('data.status', 'signed');

        $this->intervention->refresh();
        $this->assertSame(ConsentMethod::Remote, $this->intervention->consent_method);
        $this->assertNotNull($this->intervention->consentement_signe_le);
        $this->assertNotNull($this->intervention->consentement_document_id);
        $this->assertNull($this->intervention->consentement_recueilli_par_id);
        $this->assertTrue($this->consentCoche());
        $this->assertNotNull(ConsentRequest::withoutGlobalScopes()->first()->signed_ip);
        Event::assertDispatched(InterventionUpdated::class);

        // Une seule signature par lien
        $this->postJson('/api/v1/public/consentements/'.$jeton.'/signer', ['signataire' => 'Marc Fournier', 'signature' => self::SIGNATURE])->assertStatus(410);
        $this->getJson('/api/v1/public/consentements/'.$jeton)->assertOk()->assertJsonPath('data.status', 'signed')->assertJsonMissingPath('data.procedure');
    }

    public function test_par_sms_le_lien_part_au_mobile(): void
    {
        $this->envoyer(['sms'])->assertOk()->assertJsonPath('data.consent_request.channels', ['sms']);
        $this->assertCount(1, $this->requetesTwilio);
        $this->assertSame('+41791234567', $this->requetesTwilio[0]['data']['To']);
        $this->assertMatchesRegularExpression('#/consentement/[A-Za-z0-9]{48}#', $this->requetesTwilio[0]['data']['Body']);
    }

    public function test_un_nouvel_envoi_annule_le_lien_precedent(): void
    {
        $this->envoyer(['email'])->assertOk();
        $premier = $this->jetonEnvoye();
        Mail::fake();
        $this->envoyer(['email'])->assertOk();

        $this->getJson('/api/v1/public/consentements/'.$premier)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/v1/public/consentements/'.$premier.'/signer', ['signataire' => 'Marc Fournier', 'signature' => self::SIGNATURE])->assertStatus(410);
    }

    public function test_le_lien_ne_vaut_que_24_heures(): void
    {
        $this->envoyer(['email'])->assertOk();
        $jeton = $this->jetonEnvoye();
        $this->travel(25)->hours();

        $this->getJson('/api/v1/public/consentements/'.$jeton)->assertOk()->assertJsonPath('data.status', 'expired');
        $this->postJson('/api/v1/public/consentements/'.$jeton.'/signer', ['signataire' => 'Marc Fournier', 'signature' => self::SIGNATURE])->assertStatus(410);
    }

    public function test_un_canal_sans_coordonnee_est_refuse(): void
    {
        $this->client->update(['email' => null]);
        $this->envoyer(['email'])->assertUnprocessable()->assertJsonValidationErrors('channels');
        $this->envoyer(['space'])->assertUnprocessable()->assertJsonValidationErrors('channels');
        $this->assertSame(0, ConsentRequest::withoutGlobalScopes()->count());
    }

    public function test_dans_l_espace_le_proprietaire_voit_et_signe_seulement_ce_qui_lui_est_envoye(): void
    {
        ['user' => $user, 'jeton' => $jetonProprio] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $user->id]);
        app(ContexteClinique::class)->oublier();

        $this->envoyer(['space'])->assertOk();

        ['jeton' => $autre] = $this->proprietaireAvecJeton();
        $this->getJson('/api/v1/espace/consentements', $this->enTetes($autre))->assertOk()->assertJsonCount(0, 'data');

        $liste = $this->getJson('/api/v1/espace/consentements', $this->enTetes($jetonProprio))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient.nom', 'Bagheera');
        $id = $liste->json('data.0.id');

        $this->postJson('/api/v1/espace/consentements/'.$id.'/signer', ['signataire' => 'Marc Fournier', 'signature' => self::SIGNATURE], $this->enTetes($autre))->assertNotFound();
        $this->postJson('/api/v1/espace/consentements/'.$id.'/signer', ['signataire' => 'Marc Fournier', 'signature' => self::SIGNATURE], $this->enTetes($jetonProprio))->assertOk();
        $this->assertTrue($this->consentCoche());
        $this->getJson('/api/v1/espace/consentements', $this->enTetes($jetonProprio))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_accord_oral_note_sous_la_responsabilite_du_veterinaire(): void
    {
        $this->postJson($this->url('/sans-signature'), ['method' => 'oral', 'responsibility' => true], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['given_by', 'means', 'explanation']);
        $this->postJson($this->url('/sans-signature'), ['method' => 'oral', 'given_by' => 'Marc Fournier', 'means' => 'phone', 'explanation' => 'Risques expliqués'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('responsibility');

        $this->postJson($this->url('/sans-signature'), [
            'method' => 'oral', 'given_by' => 'Marc Fournier', 'means' => 'phone', 'explanation' => 'Risques de l’anesthésie, devis de 480 CHF', 'responsibility' => true,
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.consent_method', 'oral')
            ->assertJsonPath('data.consent_details.given_by', 'Marc Fournier')
            ->assertJsonPath('data.consent_details.means', 'phone')
            ->assertJsonPath('data.consent_collector.id', $this->veterinaire->id);
        $this->assertTrue($this->consentCoche());

        // Déjà recueilli : ni un autre accord, ni un envoi
        $this->postJson($this->url('/sans-signature'), ['method' => 'emergency', 'responsibility' => true], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->envoyer(['email'])->assertUnprocessable();
    }

    public function test_urgence_sans_texte_a_ecrire(): void
    {
        $this->envoyer(['email'])->assertOk();
        $jeton = $this->jetonEnvoye();

        $this->postJson($this->url('/sans-signature'), ['method' => 'emergency', 'responsibility' => true], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.consent_method', 'emergency')
            ->assertJsonPath('data.consent_details', null)
            ->assertJsonPath('data.consent_request', null);
        $this->assertTrue($this->consentCoche());
        // Le lien envoyé ne sert plus
        $this->getJson('/api/v1/public/consentements/'.$jeton)->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_signe_a_la_clinique_la_methode_est_gardee(): void
    {
        $this->postJson($this->url(), ['signataire' => 'Marc Fournier', 'signature' => self::SIGNATURE], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.consent_method', 'signed')
            ->assertJsonPath('data.consent_collector.id', $this->veterinaire->id);
    }

    public function test_l_intervention_dit_ou_envoyer(): void
    {
        $this->getJson('/api/v1/bloc/interventions/'.$this->intervention->id, $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.patient.contact.mobile', '+41791234567')
            ->assertJsonPath('data.patient.contact.email', 'm.fournier@example.ch')
            ->assertJsonPath('data.patient.contact.has_space', false);
    }

    public function test_un_envoi_en_partie_rate_garde_seulement_ce_qui_est_parti(): void
    {
        $this->fauxTwilio(500, ['message' => 'panne']);
        $this->envoyer(['email', 'sms'])->assertOk()
            ->assertJsonPath('data.consent_request.channels', ['email'])
            ->assertJsonPath('failures', ['Le SMS n’est pas parti.']);
    }

    public function test_si_rien_ne_part_l_ancien_lien_reste_valable(): void
    {
        $this->envoyer(['email'])->assertOk();
        $ancien = $this->jetonEnvoye();
        $this->fauxTwilio(500, ['message' => 'panne']);

        $this->envoyer(['sms'])->assertUnprocessable()->assertJsonValidationErrors('channels');
        $this->getJson('/api/v1/public/consentements/'.$ancien)->assertOk()->assertJsonPath('data.status', 'open');
        $this->assertSame(1, ConsentRequest::withoutGlobalScopes()->count());
    }

    public function test_une_intervention_terminee_ne_recoit_plus_de_consentement(): void
    {
        $this->intervention->forceFill(['statut' => StatutIntervention::Fini])->save();
        $this->envoyer(['email'])->assertUnprocessable();
        $this->postJson($this->url('/sans-signature'), ['method' => 'emergency', 'responsibility' => true], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    public function test_un_lien_remplace_pendant_la_signature_ne_signe_rien(): void
    {
        $this->envoyer(['email'])->assertOk();
        $jeton = $this->jetonEnvoye();
        $demande = ConsentRequest::byToken($jeton);
        // La clinique renvoie un lien entre la lecture et la signature
        ConsentRequest::withoutGlobalScopes()->whereKey($demande->id)->update(['cancelled_at' => now()]);

        try {
            app(ConsentRecorder::class)->sign($demande->intervention, 'Marc Fournier', (string) base64_decode(substr(self::SIGNATURE, 22)), ConsentMethod::Remote, null, $demande);
            $this->fail('La signature aurait dû être refusée.');
        } catch (HttpException $e) {
            $this->assertSame(410, $e->getStatusCode());
        }
        $this->assertFalse($this->consentCoche());
        $this->assertNull($this->intervention->fresh()->consentement_signe_le);
    }
}
