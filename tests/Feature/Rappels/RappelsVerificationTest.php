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
use App\Services\EtatVaccinal;
use App\Services\Rappels\RappelsProprietaires;
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
            // En UTC, comme l'application l'écrit (sinon l'heure de Zurich serait relue comme UTC)
            $rendezVous->forceFill(['created_at' => $pris->utc()])->saveQuietly();
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
        // Une téléconsultation à venir se dit comme telle ; le SMS ne promet pas de lien par e-mail
        $this->rendezVous('2026-11-06 09:00', TypeRendezVous::Teleconsultation);
        $this->assertSame('Téléconsultation', $this->aVenir()->firstWhere('objet', 'rdv')['libelle']);
        $this->assertSame('Deux Rives : téléconsultation de Félix demain à 14:30. Un empêchement : 021 600 00 00.', RappelsProprietaires::texteSms($this->clinique, $this->patient, null, RendezVous::query()->where('type', TypeRendezVous::Teleconsultation)->orderBy('starts_at')->first(), 'rdv'));
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

    /** Un rendez-vous pris bien avant (rappel parti la veille, ou réglage allumé ce jour-là) : pas de rappel le matin même */
    public function test_pas_de_vague_le_matin_pour_les_rendez_vous_pris_plus_tot(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:05', 'Europe/Zurich'));
        $this->rendezVous('2026-11-03 15:00', pris: CarbonImmutable::parse('2026-10-24 09:00', 'Europe/Zurich'));
        $this->rendezVous('2026-11-03 16:00', pris: CarbonImmutable::parse('2026-11-02 18:00', 'Europe/Zurich'));
        // Pris après 19 h 05, mais moins de deux heures avant : trop tard
        $this->rendezVous('2026-11-03 11:30', pris: CarbonImmutable::parse('2026-11-02 21:00', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertNotSent(RappelRendezVous::class);
    }

    /** La relance d'un devis : le septième jour, ou les deux suivants ; pas le dixième, comme « À venir » le dit */
    public function test_la_relance_d_un_devis_ne_part_pas_le_dixieme_jour(): void
    {
        $this->devis(['envoye_le' => CarbonImmutable::parse('2026-10-23 08:00', 'Europe/Zurich')]);
        $this->assertNull($this->aVenir()->firstWhere('objet', 'devis'));
        $this->envoyer();
        Mail::assertNotSent(RelanceDevis::class);
    }

    /** Un rappel à la main la veille de l'échéance n'est pas la relance « en retard » ; le jour même, si */
    public function test_la_borne_du_rappel_a_la_main(): void
    {
        $avant = $this->vaccin('2026-11-03');
        $leJour = $this->vaccin('2026-11-03', 'Leucose');
        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$avant->id}/rappel", [], $this->enTetes($this->jeton))->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/Zurich'));
        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$leJour->id}/rappel", [], $this->enTetes($this->jeton))->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Europe/Zurich'));
        $this->envoyer();
        $this->assertSame([$avant->id], RappelEnvoye::query()->where('objet', 'vaccin_retard')->distinct()->pluck('vaccination_id')->all());
    }

    /** Le carnet relu par l'onglet Vaccins garde « renouvelée » et le dernier rappel parti */
    public function test_la_liste_des_injections_dit_renouvelee_et_rappel_envoye(): void
    {
        $ancienne = $this->vaccin('2026-11-12');
        $leucose = $this->vaccin('2026-11-20', 'Leucose');
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Rage', 'injectee_le' => '2026-10-30', 'echeance_le' => '2027-10-30']);
        $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$leucose->id}/rappel", [], $this->enTetes($this->jeton))->assertOk();

        $liste = collect($this->getJson("/api/v1/patients/{$this->patient->id}/vaccinations", $this->enTetes($this->jeton))->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($liste[$ancienne->id]['est_renouvelee']);
        $this->assertFalse($liste[$leucose->id]['est_renouvelee']);
        $this->assertNotNull($liste[$leucose->id]['rappel_envoye_le']);
        $this->assertNull($liste[$ancienne->id]['rappel_envoye_le']);
    }

    /** La fenêtre exacte : pris à 19 h 02, le passage de 19 h 05 l'a rappelé ; pris à 19 h 30, rappelé le matin */
    public function test_la_fenetre_du_dernier_passage(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 08:05', 'Europe/Zurich'));
        $this->rendezVous('2026-11-03 14:00', pris: CarbonImmutable::parse('2026-11-02 19:02', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertNotSent(RappelRendezVous::class);

        $this->rendezVous('2026-11-03 15:00', pris: CarbonImmutable::parse('2026-11-02 19:30', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertSent(RappelRendezVous::class, 1);
    }

    /** « À venir » suit la même règle : un rendez-vous pris bien avant, pas rappelé la veille, est dit trop tard, pas « programmé » ce matin */
    public function test_a_venir_ne_promet_pas_le_rappel_du_matin_a_un_ancien_rendez_vous(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', 'Europe/Zurich'));
        $this->rendezVous('2026-11-03 15:00', pris: CarbonImmutable::parse('2026-10-20 10:00', 'Europe/Zurich'));
        $this->assertSame('Échéance passée avant le prochain envoi', $this->aVenir()->firstWhere('objet', 'rdv')['bloque']);
    }

    /** Le neuvième jour (dernier jour de rattrapage) part encore, au jour de la clinique */
    public function test_la_relance_d_un_devis_part_encore_le_neuvieme_jour_au_jour_de_la_clinique(): void
    {
        // Envoyé le 24.10 à 00 h 30 à Zurich (le 23.10 en UTC) : neuvième jour le 02.11
        $this->devis(['envoye_le' => CarbonImmutable::parse('2026-10-24 00:30', 'Europe/Zurich')->utc()]);
        $this->envoyer();
        Mail::assertSent(RelanceDevis::class, 1);
    }

    /** L'espace du propriétaire (et la montre) suit la règle unique : un vaccin refait par un combiné n'y est plus dû */
    public function test_l_espace_du_proprietaire_suit_la_regle_unique(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $user->id]);
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Rage', 'valences' => ['rage'], 'injectee_le' => '2025-11-12', 'echeance_le' => '2026-11-12']);
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Combiné', 'valences' => ['carre', 'rage'], 'injectee_le' => '2026-10-01', 'echeance_le' => '2027-10-01']);

        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Leucose', 'valences' => ['leucose'], 'injectee_le' => '2025-11-10', 'echeance_le' => '2026-11-10']);

        $rappels = $this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))->assertOk()->json('data.rappels');
        $this->assertSame(['Leucose'], collect($rappels)->pluck('vaccin')->all());
    }

    /** La réponse d'un ajout dit « renouvelée » comme la base (injection antidatée) */
    public function test_la_reponse_d_un_ajout_dit_renouvelee_comme_la_base(): void
    {
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Rage', 'injectee_le' => '2026-06-01', 'echeance_le' => '2027-06-01']);
        $reponse = $this->postJson("/api/v1/patients/{$this->patient->id}/vaccinations", ['nom' => 'Rage', 'injectee_le' => '2025-06-01', 'echeance_le' => '2026-06-01'], $this->enTetes($this->jeton));
        $reponse->assertCreated()->assertJsonPath('data.est_renouvelee', true);
    }

    /** Un ancien « Rage » en texte libre refait par un « Rage » du catalogue : l'onglet Vaccins et le carnet de l'espace n'y voient plus d'échéance */
    public function test_l_etat_par_valence_suit_la_regle_unique(): void
    {
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'produit_id' => null, 'nom' => 'Rage', 'valences' => null, 'injectee_le' => '2025-10-01', 'echeance_le' => '2026-10-01']);
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'produit_id' => null, 'nom' => 'Rage', 'valences' => ['rage'], 'injectee_le' => '2026-10-20', 'echeance_le' => '2027-10-20']);

        $etat = $this->getJson("/api/v1/patients/{$this->patient->id}/vaccinations", $this->enTetes($this->jeton))->assertOk()->json('etat');
        $this->assertSame(['rage'], array_column($etat, 'valence'));
        $this->assertSame('ok', $etat[0]['statut']);
    }

    /** La réponse d'une modification dit « renouvelée » comme la base (date reculée) */
    public function test_la_reponse_d_une_modification_dit_renouvelee_comme_la_base(): void
    {
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Rage', 'injectee_le' => '2026-06-01', 'echeance_le' => '2027-06-01']);
        $autre = Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Rage', 'injectee_le' => '2026-08-01', 'echeance_le' => '2027-08-01']);
        $this->putJson("/api/v1/patients/{$this->patient->id}/vaccinations/{$autre->id}", ['nom' => 'Rage', 'injectee_le' => '2025-06-01', 'echeance_le' => '2026-06-01'], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.est_renouvelee', true);
    }

    /** Pris la veille au soir pour 9 h 30 : l'envoi de 8 h 05 ne le prendra pas (moins de deux heures), « À venir » le dit */
    public function test_a_venir_respecte_les_deux_heures_du_rappel_du_matin(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 21:00', 'Europe/Zurich'));
        $this->rendezVous('2026-11-03 09:30', pris: CarbonImmutable::parse('2026-11-02 20:30', 'Europe/Zurich'));
        $this->assertSame('Échéance passée avant le prochain envoi', $this->aVenir()->firstWhere('objet', 'rdv')['bloque']);
    }

    /** Deux injections contre la rage le même jour : la ligne Rage reste, quel que soit l'ordre de chargement */
    public function test_deux_injections_le_meme_jour_gardent_leur_maladie(): void
    {
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'produit_id' => null, 'nom' => 'Rabisin', 'valences' => ['rage'], 'injectee_le' => '2026-10-01', 'echeance_le' => '2027-10-01']);
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'produit_id' => null, 'nom' => 'Nobivac Rabies', 'valences' => ['rage'], 'injectee_le' => '2026-10-01', 'echeance_le' => '2027-10-01']);
        $etat = $this->getJson("/api/v1/patients/{$this->patient->id}/vaccinations", $this->enTetes($this->jeton))->assertOk()->json('etat');
        $this->assertSame(['rage'], array_column($etat, 'valence'));

        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $user->id]);
        $carnet = $this->getJson("/api/v1/espace/animaux/{$this->patient->id}", $this->enTetes($jeton))->assertOk()->json('data.vaccinations');
        $this->assertSame(['rage'], array_column($carnet, 'valence'));
    }

    /** Un ancien « Rage » saisi à la main, refait par un vaccin du catalogue contre la rage : plus dû nulle part, pas de relance */
    public function test_un_rage_libre_refait_par_un_vaccin_du_catalogue_n_est_plus_du(): void
    {
        $libre = Vaccination::factory()->create(['patient_id' => $this->patient->id, 'produit_id' => null, 'nom' => 'Rage', 'valences' => null, 'injectee_le' => '2025-10-26', 'echeance_le' => '2026-10-26']);
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'produit_id' => null, 'nom' => 'Nobivac Rabies', 'valences' => ['rage'], 'injectee_le' => '2026-10-28', 'echeance_le' => '2027-10-28']);

        $this->assertTrue((bool) $libre->fresh()->renouvelee);
        $this->envoyer();
        Mail::assertNotSent(RappelVaccin::class);
        $etat = $this->getJson("/api/v1/patients/{$this->patient->id}/vaccinations", $this->enTetes($this->jeton))->json('etat');
        $this->assertSame(['rage'], array_column($etat, 'valence'));
    }

    /**
     * Les noms que l'app et les cliniques emploient : renouvelés quand chaque maladie nommée est couverte ;
     * une partie inconnue (« Rage, Rabisin »), et le nom reste libre (sixième vérification du 01.11.2026)
     */
    public function test_les_noms_saisis_a_la_main_que_le_renouvellement_reconnait(): void
    {
        $cas = [
            ['Leucose (FeLV)', ['leucose'], true],
            // Dixième vérification : deux parenthèses connues complètent ; une partie vide ou un séparateur seul laisse le nom libre ;
            // « E. coli » et « Mammites » désignent plusieurs maladies
            ['Toux de chenil (Bordetella) (Parainfluenza)', ['bordetellose'], false],
            ['Toux de chenil (Bordetella) (Parainfluenza)', ['bordetellose', 'parainfluenza'], true],
            ['Rage ()', ['leptospirose', 'rage'], false],
            ['Rage +', ['rage'], false],
            ['+ Rage', ['rage'], false],
            ['E. coli', ['colibacillose'], false],
            ['Mammites', ['mammite_staph'], false],
            // Confirmation : « (+ X) » ajoute, ne précise jamais ; « E. coli » exige ses trois maladies
            ['Coryza (+ Calici)', ['calicivirose'], false],
            ['Coryza (+ Calici)', ['calicivirose', 'herpes_felin'], true],
            ['Coryza (& Calici)', ['calicivirose'], false],
            ['Coryza (et Calici)', ['calicivirose'], false],
            ['Toux de chenil (+ Bordetella)', ['bordetellose'], false],
            ['E. coli', ['colibacillose', 'mammite_staph'], false],
            ['E. coli', ['colibacillose', 'maladie_oedeme'], false],
            ['E. coli', ['colibacillose', 'mammite_staph', 'maladie_oedeme'], true],
            ['E. coli (Œdème)', ['maladie_oedeme'], true],
            ['Typhus / Coryza', ['panleucopenie', 'herpes_felin', 'calicivirose'], true],
            ['Typhus / Coryza', ['panleucopenie'], false],
            ['VHD', ['rhd'], true],
            ['Parvo et Lepto', ['parvovirose', 'leptospirose', 'carre'], true],
            ['Maladie hémorragique virale du lapin (RHD/VHD)', ['rhd'], true],
            ['RAGE', ['rage'], true],
            ['Rage, Rabisin', ['rage'], false],
            ['Rage', ['carre'], false],
        ];
        foreach ($cas as [$nom, $valences, $attendu]) {
            $patient = Patient::factory()->create(['organisation_id' => $this->patient->organisation_id]);
            $ancienne = Vaccination::factory()->create(['patient_id' => $patient->id, 'produit_id' => null, 'nom' => $nom, 'valences' => null, 'injectee_le' => '2025-10-01', 'echeance_le' => '2026-10-01']);
            Vaccination::factory()->create(['patient_id' => $patient->id, 'produit_id' => null, 'nom' => 'Catalogue', 'valences' => $valences, 'injectee_le' => '2026-10-20', 'echeance_le' => '2027-10-20']);
            $this->assertSame($attendu, (bool) $ancienne->fresh()->renouvelee, $nom.' + '.implode(',', $valences));
        }
    }

    /** Le nom libre ne change ni l'affichage ni la primo-vaccination : seule la règle du renouvellement le lit */
    public function test_le_nom_libre_garde_son_nom_et_sa_valence_libre(): void
    {
        $this->assertSame(['libre:chlamydiose'], array_keys(EtatVaccinal::valencesDe(new Vaccination(['nom' => 'Chlamydiose']))));
        $this->assertSame('Chlamydiose', EtatVaccinal::valencesDe(new Vaccination(['nom' => 'Chlamydiose']))['libre:chlamydiose']['nom']);
        $this->assertSame(['libre:parvovirose'], array_keys(EtatVaccinal::valencesDe(new Vaccination(['nom' => 'Parvovirose']))));
    }

    /** Les deux heures, à la borne : 2 h 30 avant, le rappel du matin part ; 1 h 45 avant, non */
    public function test_les_deux_heures_du_rappel_du_matin_a_la_borne(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 08:05', 'Europe/Zurich'));
        $this->rendezVous('2026-11-03 10:35', pris: CarbonImmutable::parse('2026-11-02 20:00', 'Europe/Zurich'));
        $this->rendezVous('2026-11-03 09:50', pris: CarbonImmutable::parse('2026-11-02 20:00', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertSent(RappelRendezVous::class, 1);
        Mail::assertSent(RappelRendezVous::class, fn (RappelRendezVous $m) => str_contains($m->render(), 'à 10:35'));
    }

    /** « À venir » à la même borne : 2 h 30 après le passage de 8 h 05, programmé (moins de deux heures : voir test_a_venir_respecte_les_deux_heures) */
    public function test_a_venir_aux_deux_heures_a_la_borne(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 21:00', 'Europe/Zurich'));
        $this->rendezVous('2026-11-03 10:35', pris: CarbonImmutable::parse('2026-11-02 20:30', 'Europe/Zurich'));
        $this->assertNull($this->aVenir()->firstWhere('objet', 'rdv')['bloque']);
    }

    /** Le PDF du carnet de l'espace sait qu'une injection est renouvelée */
    public function test_le_carnet_de_l_espace_dit_renouvelee(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $user->id]);
        $ancienne = Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Rage', 'valences' => ['rage'], 'injectee_le' => '2025-10-01', 'echeance_le' => '2026-10-01']);
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Rage', 'valences' => ['rage'], 'injectee_le' => '2026-10-20', 'echeance_le' => '2027-10-20']);

        $carnet = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jeton))->assertOk()->json('data'))->firstWhere('type', 'CARNET');
        $this->assertSame([false, true], array_column($carnet['pdf']['vaccinations'], 'est_renouvelee'));
        $this->assertSame('2025-10-01', $carnet['pdf']['vaccinations'][1]['injectee_le']);
        $this->assertTrue((bool) $ancienne->fresh()->renouvelee);
    }

    /** À la minute : 10 h 05 est à deux heures du passage de 8 h 05 (part), 10 h 04 non ; « À venir » dit pareil */
    public function test_les_deux_heures_a_la_minute(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 21:00', 'Europe/Zurich'));
        $limite = $this->rendezVous('2026-11-03 10:05', pris: CarbonImmutable::parse('2026-11-02 20:00', 'Europe/Zurich'));
        $this->rendezVous('2026-11-03 10:04', pris: CarbonImmutable::parse('2026-11-02 20:00', 'Europe/Zurich'));
        $lignes = $this->aVenir()->where('objet', 'rdv')->values();
        $this->assertSame([null, 'Échéance passée avant le prochain envoi'], $lignes->sortByDesc(fn ($l) => $l['bloque'] === null)->pluck('bloque')->values()->all());

        $this->travelTo(CarbonImmutable::parse('2026-11-03 08:05', 'Europe/Zurich'));
        $this->envoyer();
        Mail::assertSent(RappelRendezVous::class, 1);
        Mail::assertSent(RappelRendezVous::class, fn (RappelRendezVous $m) => $m->rendezVous->is($limite));
    }

    /**
     * Jamais renouvelé à tort (septième vérification du 01.11.2026) : un nom qui peut désigner plusieurs
     * maladies exige qu'elles soient toutes couvertes ; les parenthèses précisent ; les séparateurs au premier niveau
     */
    public function test_le_renouvellement_par_le_nom_n_est_jamais_a_tort(): void
    {
        $cas = [
            ['Toux de chenil', ['carre', 'hepatite', 'parvovirose', 'parainfluenza'], false],
            ['Toux de chenil', ['parainfluenza', 'bordetellose'], true],
            ['Toux de chenil (Bordetella)', ['carre', 'hepatite', 'parvovirose', 'parainfluenza'], false],
            ['Toux de chenil (Bordetella)', ['bordetellose'], true],
            ['Toux de chenil (Bordetella + Pi)', ['bordetellose'], false],
            ['Coryza', ['herpes_felin'], false],
            ['Typhus / Coryza', ['panleucopenie', 'herpes_felin'], false],
            ['Carré · Parvo', ['carre', 'parvovirose'], true],
            ['Myxomatose & VHD', ['myxomatose', 'rhd'], true],
            ['Carré + Rage', ['carre'], false],
            ['Sérum', ['septicemie_poulain'], false],
            ['/', ['rage'], false],
            // Une parenthèse qui complète : toutes ses maladies exigées (huitième vérification du 01.11.2026)
            ['Rage (+ Lepto)', ['leptospirose'], false],
            ['Rage (+ Lepto)', ['rage', 'leptospirose'], true],
            ['Myxomatose (+ VHD)', ['rhd'], false],
            ['Carré (Parvo)', ['parvovirose'], false],
            ['Pas la rage (Leucose)', ['leucose'], false],
            // Le nom entier d'abord (« Coryza (herpès) » est le nom court de l'herpès félin) ; le repli sur le nom sans parenthèses
            ['Coryza (herpès)', ['herpes_felin'], true],
            // Une parenthèse inconnue peut ajouter une maladie : le nom reste libre, un rappel de trop plutôt qu'un rappel perdu
            ['Rage (Rabisin)', ['rage'], false],
            ['Toux de chenil (Pi)', ['parainfluenza', 'bordetellose'], false],
            ['Rage (+ Lepto)', ['rage'], false],
            ['Myxomatose (+ VHD2)', ['myxomatose'], false],
            ['Rage (avec Lepto)', ['rage'], false],
            ['Rage (Lepto + Pi)', ['rage'], false],
            ['Toux de chenil (Bordetella) (Pi)', ['bordetellose'], false],
            ['Coryza (Calici) (+ Herpès)', ['calicivirose'], false],
            ['Leucose (FeLV)', ['leucose'], true],
            // « Herpès » seul ne nomme pas une maladie
            ['Herpès', ['herpes_felin'], false],
        ];
        foreach ($cas as [$nom, $valences, $attendu]) {
            $patient = Patient::factory()->create(['organisation_id' => $this->patient->organisation_id]);
            $ancienne = Vaccination::factory()->create(['patient_id' => $patient->id, 'produit_id' => null, 'nom' => $nom, 'valences' => null, 'injectee_le' => '2025-10-01', 'echeance_le' => '2026-10-01']);
            Vaccination::factory()->create(['patient_id' => $patient->id, 'produit_id' => null, 'nom' => 'Catalogue', 'valences' => $valences, 'injectee_le' => '2026-10-20', 'echeance_le' => '2027-10-20']);
            $this->assertSame($attendu, (bool) $ancienne->fresh()->renouvelee, $nom.' + '.implode(',', $valences));
        }
    }

    /** Une plus récente saisie à la main couvre ce qu'elle nomme sans ambiguïté : « Rage » oui, « Toux de chenil » non */
    public function test_une_plus_recente_saisie_a_la_main_couvre_sans_ambiguite(): void
    {
        $rage = Vaccination::factory()->create(['patient_id' => $this->patient->id, 'produit_id' => null, 'nom' => 'Rabisin', 'valences' => ['rage'], 'injectee_le' => '2025-10-01', 'echeance_le' => '2026-10-01']);
        $bb = Vaccination::factory()->create(['patient_id' => $this->patient->id, 'produit_id' => null, 'nom' => 'Nobivac Bb', 'valences' => ['bordetellose'], 'injectee_le' => '2025-10-01', 'echeance_le' => '2026-10-01']);
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'produit_id' => null, 'nom' => 'Rage', 'valences' => null, 'injectee_le' => '2026-10-20', 'echeance_le' => '2027-10-20']);
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'produit_id' => null, 'nom' => 'Toux de chenil', 'valences' => null, 'injectee_le' => '2026-10-20', 'echeance_le' => '2027-10-20']);
        $this->assertTrue((bool) $rage->fresh()->renouvelee);
        $this->assertFalse((bool) $bb->fresh()->renouvelee);
    }
}
