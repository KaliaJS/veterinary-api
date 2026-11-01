<?php

namespace Tests\Feature\Rappels;

use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Enums\StatutOrdonnance;
use App\Enums\TypeRendezVous;
use App\Mail\RappelRendezVous;
use App\Mail\RappelVaccin;
use App\Mail\RelanceDevis;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\RappelEnvoye;
use App\Models\RendezVous;
use App\Models\User;
use App\Models\Vaccination;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * L'écran « Rappels » (01.11.2026, choix A de Jim : « un onglet Rappels
 * qui réunit tous les rappels, vaccins, rendez-vous, devis ») et les trous
 * relevés le même jour : chaque rendez-vous rappelé la veille (l'accueil
 * public le promettait), une relance de vaccin en retard, une relance de
 * devis sans réponse ; un rappel envoyé à la main n'est pas suivi d'un
 * automatique ; un vaccin renouvelé n'est plus dû ; ce qui attend
 * l'équipe ; l'aperçu des messages.
 */
class RappelsEcranTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Client $client;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
        $organisation = $this->clinique->societe->organisation_id;
        $this->client = Client::factory()->create(['organisation_id' => $organisation, 'prenom' => 'Léa', 'nom' => 'Martin', 'email' => 'lea.martin@example.ch', 'tel' => '079 123 45 67']);
        $this->patient = Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $this->client->id, 'nom' => 'Félix']);
        RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subMonths(11), 'ends_at' => now()->subMonths(11)->addMinutes(20)]);
    }

    private function envoyer(): void
    {
        $this->artisan('rappels:envoyer')->assertSuccessful();
    }

    private function reglages(array $reglages): void
    {
        $this->putJson('/api/v1/rappels/reglages', $reglages, $this->enTetes($this->jeton))->assertOk();
    }

    private function vaccin(string $echeance, string $nom = 'Rage', ?string $injectee = null): Vaccination
    {
        return Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => $nom, 'injectee_le' => $injectee ?? CarbonImmutable::parse($echeance)->subYear(), 'echeance_le' => $echeance]);
    }

    private function rendezVous(string $quand, TypeRendezVous $type = TypeRendezVous::Consultation): RendezVous
    {
        $debut = CarbonImmutable::parse($quand, 'Europe/Zurich')->utc();

        return RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'type' => $type, 'starts_at' => $debut, 'ends_at' => $debut->addMinutes(20)]);
    }

    private function devis(array $attributs = []): Devis
    {
        $devis = Devis::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id,
            'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail, 'envoye_le' => now()->subDays(8), 'valide_jusquau' => now()->addDays(22), ...$attributs,
        ]);
        DevisLigne::factory()->create(['devis_id' => $devis->id]);

        return $devis;
    }

    /** « Un rappel vous attend la veille » : chaque rendez-vous, une fois, la veille */
    public function test_chaque_rendez_vous_est_rappele_la_veille_une_seule_fois(): void
    {
        $this->rendezVous('2026-11-03 14:30');
        $this->rendezVous('2026-11-05 09:00');

        $this->envoyer();
        $this->envoyer();

        Mail::assertSent(RappelRendezVous::class, 1);
        Mail::assertSent(RappelRendezVous::class, fn (RappelRendezVous $mail) => $mail->hasTo('lea.martin@example.ch')
            && str_contains($mail->render(), 'Félix') && str_contains($mail->render(), 'demain, le 03.11.2026 à 14:30'));
        $this->assertSame(1, RappelEnvoye::query()->where('objet', 'rdv')->count());
    }

    public function test_les_rappels_de_rendez_vous_se_coupent_et_un_controle_n_est_pas_rappele_deux_fois(): void
    {
        $this->rendezVous('2026-11-03 14:30', TypeRendezVous::Controle);
        // Ni une euthanasie, ni une urgence : pas de « nous attendons Félix demain »
        $this->rendezVous('2026-11-03 15:30', TypeRendezVous::Euthanasie);
        $this->rendezVous('2026-11-03 17:30', TypeRendezVous::Urgence);
        $this->envoyer();
        Mail::assertNotSent(RappelRendezVous::class);

        $this->rendezVous('2026-11-03 16:00');
        $this->reglages(['rendez_vous' => false]);
        $this->envoyer();
        Mail::assertNotSent(RappelRendezVous::class);
    }

    /** Un vaccin en retard : une relance sept jours après l'échéance, une fois, s'il n'est pas renouvelé */
    public function test_un_vaccin_en_retard_est_relance_une_fois_sept_jours_apres(): void
    {
        $this->vaccin('2026-10-30');
        $this->envoyer();
        Mail::assertNotSent(RappelVaccin::class, fn (RappelVaccin $m) => $m->estEnRetard);

        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00', 'Europe/Zurich'));
        $this->envoyer();
        $this->envoyer();
        Mail::assertSent(RappelVaccin::class, fn (RappelVaccin $m) => $m->estEnRetard && str_contains($m->render(), 'était prévu pour le 30.10.2026'));
        $this->assertSame(1, RappelEnvoye::query()->where('objet', 'vaccin_retard')->count());
    }

    public function test_un_vaccin_renouvele_ou_une_relance_coupee_ne_part_pas(): void
    {
        $this->vaccin('2026-10-30');
        $this->vaccin('2027-11-01', 'Rage', '2026-11-01');
        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertNotSent(RappelVaccin::class);

        $this->vaccin('2026-10-29', 'Leucose');
        $this->reglages(['relance_vaccin_retard' => false]);
        $this->envoyer();
        Mail::assertNotSent(RappelVaccin::class);
    }

    /** Un devis envoyé en ligne, sans réponse depuis sept jours : une relance, notée sur le devis */
    public function test_un_devis_sans_reponse_est_relance_une_fois(): void
    {
        $this->client->update(['user_id' => User::factory()->create(['email' => 'lea@espace.ch'])->id]);
        $devis = $this->devis();
        $this->devis(['envoye_le' => now()->subDays(3)]);
        $this->devis(['relance_le' => now()->subDay()]);
        $this->devis(['canal' => CanalDevis::Papier]);

        $this->envoyer();
        $this->envoyer();

        Mail::assertSent(RelanceDevis::class, 1);
        Mail::assertSent(RelanceDevis::class, fn (RelanceDevis $m) => $m->devis->is($devis) && $m->hasTo('lea@espace.ch'));
        $this->assertNotNull($devis->refresh()->relance_le);
    }

    public function test_la_relance_des_devis_se_coupe(): void
    {
        $this->devis();
        $this->reglages(['relance_devis' => false]);
        $this->envoyer();
        Mail::assertNotSent(RelanceDevis::class);
    }

    /** Rappelé à la main : l'automatique ne repart pas pour la même échéance (trou relevé le 01.11.2026) */
    public function test_un_rappel_envoye_a_la_main_n_est_pas_suivi_d_un_automatique(): void
    {
        $vaccin = $this->vaccin('2026-11-12');
        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$vaccin->id}/rappel", [], $this->enTetes($this->jeton))->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Europe/Zurich'));
        $this->envoyer();

        Mail::assertSent(RappelVaccin::class, 1);
        $ligne = collect($this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->json('data.a_venir'))->firstWhere('objet', 'vaccin');
        $this->assertNull($ligne);
    }

    /** Un vaccin déjà renouvelé ne se rappelle pas à la main, et n'est plus « dû » dans la liste des patients */
    public function test_un_vaccin_renouvele_n_est_plus_du(): void
    {
        $ancien = $this->vaccin('2026-11-05');
        $this->vaccin('2027-10-30', 'Rage', '2026-10-30');

        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$ancien->id}/rappel", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['rappel' => 'Ce vaccin a déjà été renouvelé : aucun rappel à envoyer.']);

        $patients = $this->getJson('/api/v1/patients?filtre=rappels', $this->enTetes($this->jeton))->assertOk();
        $this->assertNotContains($this->patient->id, collect($patients->json('data'))->pluck('id'));
        $this->assertSame(0, $patients->json('meta.facettes.rappels'));
        $this->assertStringStartsWith('2027-10-30', collect($this->getJson('/api/v1/patients', $this->enTetes($this->jeton))->json('data'))->firstWhere('id', $this->patient->id)['echeance_vaccinale']);
    }

    /** Le carnet sait, d'après le serveur, qu'un vaccin est renouvelé et qu'un rappel est parti (plus seulement pendant la session) */
    public function test_le_carnet_dit_renouvele_et_rappel_envoye_d_apres_le_serveur(): void
    {
        $ancien = $this->vaccin('2026-10-20', 'Rage');
        $leucose = $this->vaccin('2026-11-12', 'Leucose');
        $this->vaccin('2027-10-30', 'Rage', '2026-10-30');
        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$leucose->id}/rappel", [], $this->enTetes($this->jeton))->assertOk();

        $vaccins = collect($this->getJson("/api/v1/patients/{$this->patient->id}", $this->enTetes($this->jeton))->assertOk()->json('data.vaccinations'))->keyBy('id');
        $this->assertTrue($vaccins[$ancien->id]['est_renouvelee']);
        $this->assertFalse($vaccins[$leucose->id]['est_renouvelee']);
        $this->assertNotNull($vaccins[$leucose->id]['rappel_envoye_le']);
        $this->assertNull($vaccins[$ancien->id]['rappel_envoye_le']);
    }

    /** Les prochains rappels réunissent vaccins, rendez-vous, vaccins en retard et devis */
    public function test_les_prochains_rappels_reunissent_tous_les_types(): void
    {
        $this->vaccin('2026-11-20');
        $this->vaccin('2026-10-31', 'Leucose');
        $this->rendezVous('2026-11-10 09:00');
        $this->devis(['envoye_le' => now()->subDays(2)]);

        $objets = collect($this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->assertOk()->json('data.a_venir'))->pluck('envoi_le', 'objet');
        $this->assertSame('2026-11-13', $objets['vaccin']);
        $this->assertSame('2026-11-07', $objets['vaccin_retard']);
        $this->assertSame('2026-11-09', $objets['rdv']);
        $this->assertSame('2026-11-07', $objets['devis']);
    }

    /** Ce qui attend l'équipe : vaccin toujours en retard, renouvellement demandé, devis sans réponse */
    public function test_ce_qui_attend_l_equipe(): void
    {
        $this->vaccin('2026-10-10');
        $ordonnance = Ordonnance::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => StatutOrdonnance::Active, 'numero' => 'RX-2026-058']);
        $ordonnance->demandesRenouvellement()->create(['user_id' => User::factory()->create()->id]);
        $this->devis(['canal' => CanalDevis::Papier, 'numero' => 'DV-2026-018']);

        $lignes = collect($this->getJson('/api/v1/rappels/a-faire', $this->enTetes($this->jeton))->assertOk()->json('data'));
        $this->assertSame(['vaccin', 'devis', 'ordonnance'], $lignes->pluck('type')->all());
        $this->assertSame('Rage en retard depuis le 10.10.2026', $lignes[0]['quoi']);
        $this->assertSame('079 123 45 67', $lignes[0]['telephone']);
        $this->assertSame('Devis papier DV-2026-018 sans réponse depuis 8 jours', $lignes[1]['quoi']);
        $this->assertSame('Demande le renouvellement de RX-2026-058 depuis son espace', $lignes[2]['quoi']);
    }

    /** L'aperçu : le message tel que le client le reçoit, e-mail et SMS */
    public function test_l_apercu_montre_le_vrai_message(): void
    {
        $vaccin = $this->getJson('/api/v1/rappels/apercu/vaccin', $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame('Rappel de vaccin pour Pilou chez Deux Rives', $vaccin['sujet']);
        $this->assertStringContainsString('Prendre rendez-vous', $vaccin['html']);
        $this->assertStringContainsString('le vaccin Rage de Pilou est à faire avant le 09.11.2026', $vaccin['sms']);

        $rdv = $this->getJson('/api/v1/rappels/apercu/rdv', $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertStringContainsString('demain, le 03.11.2026 à 10:30', $rdv['html']);
        $this->assertNull($this->getJson('/api/v1/rappels/apercu/devis', $this->enTetes($this->jeton))->assertOk()->json('data.sms'));
        $this->getJson('/api/v1/rappels/apercu/anniversaire', $this->enTetes($this->jeton))->assertNotFound();
    }
}
