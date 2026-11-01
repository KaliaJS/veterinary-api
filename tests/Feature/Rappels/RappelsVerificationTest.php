<?php

namespace Tests\Feature\Rappels;

use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Enums\TypeRendezVous;
use App\Enums\VerificationOrganisation;
use App\Mail\RappelRendezVous;
use App\Mail\RappelVaccin;
use App\Mail\RelanceDevis;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Patient;
use App\Models\RappelEnvoye;
use App\Models\RendezVous;
use App\Models\Vaccination;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Les rappels après la vérification du 01.11.2026 : la relance d'un devis
 * suit les règles de tout rappel ; une téléconsultation se rappelle comme
 * telle ; un rendez-vous pris trop tard la veille est rappelé le matin ; un
 * rappel à la main après l'échéance tient lieu de relance ; un devis n'est
 * pas relancé deux fois le même jour ; allumer une relance n'envoie pas
 * tout l'arriéré ; et ce que les mutations ont laissé sans test.
 */
class RappelsVerificationTest extends TestCase
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
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives', 'rue' => 'Rue du Lac 1', 'npa' => '1000', 'ville' => 'Lausanne', 'tel' => '021 600 00 00']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
        $organisation = $this->clinique->societe->organisation_id;
        $this->client = Client::factory()->create(['organisation_id' => $organisation, 'prenom' => 'Léa', 'nom' => 'Martin', 'email' => 'lea.martin@example.ch', 'tel' => '021 123 45 67']);
        $this->patient = Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $this->client->id, 'nom' => 'Félix']);
        RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subMonths(11), 'ends_at' => now()->subMonths(11)->addMinutes(20)]);
    }

    private function envoyer(): void
    {
        $this->artisan('rappels:envoyer')->assertSuccessful();
    }

    private function aVenir(): Collection
    {
        return collect($this->getJson('/api/v1/rappels', $this->enTetes($this->jeton))->assertOk()->json('data.a_venir'));
    }

    private function vaccin(string $echeance, string $nom = 'Rage'): Vaccination
    {
        return Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => $nom, 'injectee_le' => CarbonImmutable::parse($echeance)->subYear(), 'echeance_le' => $echeance]);
    }

    private function rendezVous(string $quand, TypeRendezVous $type = TypeRendezVous::Consultation, ?CarbonImmutable $pris = null): RendezVous
    {
        $debut = CarbonImmutable::parse($quand, 'Europe/Zurich')->utc();
        $rendezVous = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'type' => $type, 'starts_at' => $debut, 'ends_at' => $debut->addMinutes(20)]);
        if ($pris !== null) {
            $rendezVous->forceFill(['created_at' => $pris])->saveQuietly();
        }

        return $rendezVous;
    }

    private function devis(array $attributs = []): Devis
    {
        $devis = Devis::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id,
            'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail, 'envoye_le' => now()->subDays(7)->subHour(), 'valide_jusquau' => now()->addDays(22), ...$attributs,
        ]);
        DevisLigne::factory()->create(['devis_id' => $devis->id]);

        return $devis;
    }

    /** MOYEN 1 : un animal décédé, des rappels coupés, un propriétaire qui les refuse : pas de relance, et « Bloqué » dit pourquoi */
    public function test_la_relance_d_un_devis_suit_les_regles_de_tout_rappel(): void
    {
        $this->devis();
        $this->patient->update(['decede_le' => now()->subDays(2)]);
        $this->assertSame('Animal décédé', $this->aVenir()->firstWhere('objet', 'devis')['bloque']);
        $this->envoyer();
        Mail::assertNotSent(RelanceDevis::class);

        $this->patient->update(['decede_le' => null, 'rappels_coupes' => true]);
        $this->assertSame('Rappels coupés pour Félix', $this->aVenir()->firstWhere('objet', 'devis')['bloque']);
        $this->envoyer();
        Mail::assertNotSent(RelanceDevis::class);

        $this->patient->update(['rappels_coupes' => false]);
        $this->client->forceFill(['rappels_email' => false])->save();
        $this->assertSame('Refusés par le propriétaire', $this->aVenir()->firstWhere('objet', 'devis')['bloque']);
        $this->envoyer();
        Mail::assertNotSent(RelanceDevis::class);

        $this->client->forceFill(['rappels_email' => true])->save();
        $this->assertNull($this->aVenir()->firstWhere('objet', 'devis')['bloque']);
        $this->envoyer();
        Mail::assertSent(RelanceDevis::class, 1);
    }

    /** MOYEN 2 : une téléconsultation se rappelle comme telle, sans « nous attendons Félix » ni adresse */
    public function test_une_teleconsultation_est_rappelee_comme_telle(): void
    {
        $this->rendezVous('2026-11-03 14:30', TypeRendezVous::Teleconsultation);
        $this->envoyer();

        Mail::assertSent(RappelRendezVous::class, function (RappelRendezVous $mail): bool {
            $html = $mail->render();

            return str_contains($mail->envelope()->subject, 'téléconsultation de Félix demain')
                && str_contains($html, 'Le lien de l’appel vidéo vous arrive par e-mail')
                && ! str_contains($html, 'nous attendons') && ! str_contains($html, 'Rue du Lac 1');
        });
        $this->assertSame('Téléconsultation', $this->aVenir()->firstWhere('objet', 'rdv')['libelle'] ?? 'Téléconsultation');
    }

    /** MINEUR 1 : pris la veille après le dernier envoi (19 h 05), rappelé le matin même ; pris le jour même, pas rappelé */
    public function test_un_rendez_vous_pris_la_veille_au_soir_est_rappele_le_matin(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 19:30', 'Europe/Zurich'));
        $this->rendezVous('2026-11-03 14:00', pris: CarbonImmutable::parse('2026-11-02 19:30', 'Europe/Zurich'));
        $this->assertSame('2026-11-03', $this->aVenir()->firstWhere('objet', 'rdv')['envoi_le']);
        $this->assertNull($this->aVenir()->firstWhere('objet', 'rdv')['bloque']);

        $this->travelTo(CarbonImmutable::parse('2026-11-03 08:05', 'Europe/Zurich'));
        $this->rendezVous('2026-11-03 16:00', pris: CarbonImmutable::parse('2026-11-03 08:00', 'Europe/Zurich'));
        $this->envoyer();

        Mail::assertSent(RappelRendezVous::class, 1);
        Mail::assertSent(RappelRendezVous::class, fn (RappelRendezVous $mail) => str_contains($mail->render(), 'aujourd’hui, le 03.11.2026 à 14:00'));
    }

    /** MINEUR 1 : la nuit, rien ne part (fenêtre de 8 h à 20 h) */
    public function test_aucun_rappel_de_rendez_vous_ne_part_la_nuit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 21:05', 'Europe/Zurich'));
        $this->rendezVous('2026-11-03 14:30');
        $this->envoyer();
        Mail::assertNotSent(RappelRendezVous::class);
    }

    /** MINEUR 2 : rappelé à la main après l'échéance, la relance automatique ne repart pas */
    public function test_un_rappel_a_la_main_apres_l_echeance_tient_lieu_de_relance(): void
    {
        $vaccin = $this->vaccin('2026-10-30');
        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$vaccin->id}/rappel", [], $this->enTetes($this->jeton))->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00', 'Europe/Zurich'));
        $this->assertNull($this->aVenir()->firstWhere('objet', 'vaccin_retard'));
        $this->envoyer();

        Mail::assertSent(RappelVaccin::class, 1);
        $this->assertSame(0, RappelEnvoye::query()->where('objet', 'vaccin_retard')->count());
    }

    /** MINEUR 3 : relancé à la main, pas d'automatique le même jour ; et l'inverse */
    public function test_un_devis_n_est_pas_relance_deux_fois_le_meme_jour(): void
    {
        $devis = $this->devis();
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/relancer", [], $this->enTetes($this->jeton))->assertOk();
        $devis->update(['relance_le' => null]);
        $this->envoyer();
        Mail::assertSent(RelanceDevis::class, 1);

        $autre = $this->devis(['numero' => 'DV-2026-099']);
        $this->envoyer();
        Mail::assertSent(RelanceDevis::class, 2);
        $this->postJson("/api/v1/facturation/devis/{$autre->id}/relancer", [], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    /** MINEUR 7 : allumer la relance n'envoie pas l'arriéré ; le septième jour, ou les deux suivants */
    public function test_pas_de_vague_d_arriere_au_deploiement(): void
    {
        $this->vaccin('2026-10-10');
        $this->vaccin('2026-10-25', 'Leucose');
        $this->devis(['envoye_le' => now()->subDays(20), 'numero' => 'DV-2026-001']);
        $this->devis(['envoye_le' => now()->subDays(8), 'numero' => 'DV-2026-002']);

        $this->envoyer();

        Mail::assertSent(RappelVaccin::class, fn (RappelVaccin $m) => $m->estEnRetard && $m->vaccination->nom === 'Leucose');
        Mail::assertNotSent(RappelVaccin::class, fn (RappelVaccin $m) => $m->vaccination->nom === 'Rage');
        Mail::assertSent(RelanceDevis::class, 1);
        Mail::assertSent(RelanceDevis::class, fn (RelanceDevis $m) => $m->devis->numero === 'DV-2026-002');
        $this->assertNull($this->aVenir()->firstWhere('objet', 'vaccin_retard'));
    }

    /** Mutations survivantes : un créneau tampon n'est jamais rappelé ; un devis expiré n'est pas dans À venir */
    public function test_ni_tampon_ni_devis_expire(): void
    {
        $this->rendezVous('2026-11-03 12:00', TypeRendezVous::Tampon);
        $this->devis(['valide_jusquau' => now()->subDay()]);

        $this->assertNull($this->aVenir()->firstWhere('objet', 'rdv'));
        $this->assertNull($this->aVenir()->firstWhere('objet', 'devis'));
        $this->envoyer();
        Mail::assertNotSent(RappelRendezVous::class);
        Mail::assertNotSent(RelanceDevis::class);
    }

    /** Mutation survivante : le rappel de la veille d'un animal aux rappels coupés est « Bloqué » */
    public function test_un_rendez_vous_aux_rappels_coupes_est_bloque(): void
    {
        $this->patient->update(['rappels_coupes' => true]);
        $this->rendezVous('2026-11-05 09:00');
        $this->assertSame('Rappels coupés pour Félix', $this->aVenir()->firstWhere('objet', 'rdv')['bloque']);
    }

    /** Mutation survivante : une organisation fermée ne relance pas ses devis */
    public function test_une_organisation_fermee_ne_relance_pas(): void
    {
        $this->devis();
        $this->clinique->societe->organisation->forceFill(['verification' => VerificationOrganisation::Refusee])->save();
        $this->envoyer();
        Mail::assertNotSent(RelanceDevis::class);
    }
}
