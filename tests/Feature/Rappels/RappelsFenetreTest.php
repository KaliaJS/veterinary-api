<?php

namespace Tests\Feature\Rappels;

use App\Enums\CanalDevis;
use App\Enums\DomainePermission;
use App\Enums\StatutDevis;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Patient;
use App\Models\RappelEnvoye;
use App\Models\RendezVous;
use App\Models\Vaccination;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La fenêtre Rappels de Patients (Jim, 11.11.2026, choix A de la maquette
 * /ui/rappels-refonte) : « Je préfère voir « Voir les 28 autres » tout le
 * temps avec un infinity scroll. » Ce qui attend l'équipe et ce qui est
 * parti se lisent page par page, filtrés par type au serveur.
 */
class RappelsFenetreTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
        $organisation = $this->clinique->societe->organisation_id;
        $client = Client::factory()->create(['organisation_id' => $organisation, 'prenom' => 'Léa', 'nom' => 'Martin', 'email' => 'lea.martin@example.ch', 'tel' => '079 123 45 67']);
        $this->patient = Patient::factory()->create(['organisation_id' => $organisation, 'client_id' => $client->id, 'nom' => 'Félix']);
        RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subMonths(11), 'ends_at' => now()->subMonths(11)->addMinutes(20)]);
    }

    private function vaccinEnRetard(string $echeance, string $nom): void
    {
        Vaccination::factory()->create(['patient_id' => $this->patient->id, 'nom' => $nom, 'injectee_le' => CarbonImmutable::parse($echeance)->subYear(), 'echeance_le' => $echeance]);
    }

    private function devisSansReponse(string $numero): void
    {
        $devis = Devis::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id,
            'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Papier, 'numero' => $numero, 'envoye_le' => now()->subDays(8), 'valide_jusquau' => now()->addDays(22),
        ]);
        DevisLigne::factory()->create(['devis_id' => $devis->id]);
    }

    private function envoye(string $objet, string $libelle, string $quand): void
    {
        RappelEnvoye::query()->create([
            'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'objet' => $objet, 'libelle' => $libelle,
            'echeance_le' => '2026-11-10', 'canal' => 'email', 'statut' => 'envoye', 'envoye_le' => CarbonImmutable::parse($quand),
        ]);
    }

    private function lire(string $chemin, array $requete = []): array
    {
        return $this->getJson('/api/v1/'.$chemin.'?'.http_build_query($requete), $this->enTetes($this->jeton))->assertOk()->json();
    }

    public function test_ce_qui_attend_l_equipe_se_lit_page_par_page(): void
    {
        $this->vaccinEnRetard('2026-10-01', 'Rage');
        $this->vaccinEnRetard('2026-10-05', 'Leucose');
        $this->devisSansReponse('DV-2026-018');

        $premiere = $this->lire('rappels/a-faire', ['per_page' => 2]);
        $this->assertCount(2, $premiere['data']);
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3], $premiere['meta']);
        $this->assertSame('Rage en retard depuis le 01.10.2026', $premiere['data'][0]['quoi']);

        $seconde = $this->lire('rappels/a-faire', ['per_page' => 2, 'page' => 2]);
        $this->assertSame(['devis'], array_column($seconde['data'], 'type'));
    }

    public function test_ce_qui_attend_l_equipe_se_filtre_par_type(): void
    {
        $this->vaccinEnRetard('2026-10-01', 'Rage');
        $this->devisSansReponse('DV-2026-018');

        $devis = $this->lire('rappels/a-faire', ['type' => 'devis']);
        $this->assertSame(['devis'], array_column($devis['data'], 'type'));
        $this->assertSame(1, $devis['meta']['total']);
        $this->getJson('/api/v1/rappels/a-faire?type=anniversaire', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    /** Un animal d'un client, connu de la clinique (un rendez-vous passé), et ses vaccins */
    private function animal(Client $client, string $nom, array $vaccins, array $attributs = []): Patient
    {
        $patient = Patient::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'client_id' => $client->id, 'nom' => $nom, ...$attributs]);
        RendezVous::factory()->pourPatient($patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subMonths(6), 'ends_at' => now()->subMonths(6)->addMinutes(20)]);
        foreach ($vaccins as [$vaccin, $echeance]) {
            Vaccination::factory()->create(['patient_id' => $patient->id, 'nom' => $vaccin, 'injectee_le' => CarbonImmutable::parse($echeance)->subYear(), 'echeance_le' => $echeance]);
        }

        return $patient;
    }

    private function client(array $attributs): Client
    {
        return Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'prenom' => 'Paul', 'nom' => 'Meunier', 'tel' => '021 601 42 42', ...$attributs]);
    }

    /** Jim, 11.11.2026 : un rappel qui ne peut pas partir faute de moyen de joindre le client attend l'équipe, avec son téléphone */
    public function test_un_rappel_qui_ne_peut_pas_partir_faute_de_contact_attend_l_equipe(): void
    {
        // Rage et Leucose le même jour (le 8 : le rappel part 7 jours avant, aujourd'hui)
        $this->animal($this->client(['email' => null]), 'Pilou', [['Rage', '2026-11-08'], ['Leucose', '2026-11-08']]);

        $lignes = collect($this->lire('rappels/a-faire')['data']);

        $this->assertSame(['Pilou', 'Pilou'], $lignes->pluck('patient.nom')->all());
        $this->assertCount(2, $lignes->pluck('id')->unique(), 'deux rappels du même animal le même jour restent deux lignes');
        $this->assertSame('vaccin', $lignes[0]['type']);
        $this->assertSame('021 601 42 42', $lignes[0]['telephone']);
        $this->assertSame(['Le rappel Leucose du 08.11.2026 ne part pas : pas d’adresse e-mail', 'Le rappel Rage du 08.11.2026 ne part pas : pas d’adresse e-mail'], $lignes->pluck('quoi')->sort()->values()->all());
        $this->assertCount(2, $this->lire('rappels/a-faire', ['type' => 'vaccin'])['data']);
        $this->assertSame([], $this->lire('rappels/a-faire', ['type' => 'rdv'])['data']);
    }

    /** Pas avant le jour où il aurait dû partir : rien de ce qui n'est « pas encore à faire » */
    public function test_pas_avant_le_jour_ou_il_aurait_du_partir(): void
    {
        $this->animal($this->client(['email' => null]), 'Pilou', [['Rage', '2026-12-10']]);

        $this->assertSame([], $this->lire('rappels/a-faire')['data']);
    }

    /** Ce qu'un choix bloque n'attend pas l'équipe : on n'appelle pas qui n'en veut pas */
    public function test_un_choix_du_client_ou_de_la_clinique_n_attend_pas_l_equipe(): void
    {
        // Rappels coupés pour l'animal
        $this->animal($this->client(['email' => null]), 'Moka', [['Rage', '2026-11-08']], ['rappels_coupes' => true]);
        // Un client qui a refusé les e-mails de rappel, sans SMS
        $this->animal($this->client(['email' => 'paul@example.ch', 'rappels_email' => false, 'rappels_sms' => false]), 'Nala', [['Rage', '2026-11-08']]);

        $this->assertSame([], $this->lire('rappels/a-faire')['data']);
    }

    /** Un client qui ne veut que des SMS, sans mobile sur sa fiche (un fixe) : l'équipe l'appelle */
    public function test_sms_seulement_sans_mobile_attend_l_equipe(): void
    {
        $this->animal($this->client(['email' => 'paul@example.ch', 'rappels_email' => false, 'rappels_sms' => true]), 'Zia', [['Rage', '2026-11-08']]);

        $lignes = $this->lire('rappels/a-faire')['data'];

        $this->assertCount(1, $lignes);
        $this->assertSame('Le rappel Rage du 08.11.2026 ne part pas : pas de numéro de mobile', $lignes[0]['quoi']);
        $this->assertSame('021 601 42 42', $lignes[0]['telephone']);
    }

    /** Vérification du 11.11.2026 : le droit Patients, la clinique du jeton, des paramètres bornés */
    public function test_sans_le_droit_patients_rien_ne_se_lit_et_une_autre_clinique_ne_voit_rien(): void
    {
        $this->envoye('vaccin', 'Rage', '2026-10-30 09:00');
        $this->vaccinEnRetard('2026-10-01', 'Rage');
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $employe->id, 'domaine' => DomainePermission::Patients, 'est_accordee' => false]);
        ['jeton' => $ailleurs] = $this->administrateurAvecJeton(Clinique::factory()->create());

        $this->getJson('/api/v1/rappels/a-faire', $this->enTetes($jeton))->assertForbidden();
        $this->getJson('/api/v1/rappels/envoyes', $this->enTetes($jeton))->assertForbidden();
        $this->getJson('/api/v1/rappels/envoyes', $this->enTetes($ailleurs))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/rappels/a-faire', $this->enTetes($ailleurs))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_des_parametres_hors_bornes_sont_refuses(): void
    {
        foreach (['rappels/a-faire', 'rappels/envoyes'] as $chemin) {
            $this->getJson('/api/v1/'.$chemin.'?per_page=101', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('per_page');
            $this->getJson('/api/v1/'.$chemin.'?page=0', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('page');
        }
        $this->getJson('/api/v1/rappels/envoyes?type=ordonnance', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_ce_qui_est_parti_se_lit_page_par_page_le_plus_recent_d_abord(): void
    {
        $this->envoye('vaccin', 'Rage', '2026-10-30 09:00');
        $this->envoye('vaccin_retard', 'Rage, en retard', '2026-11-01 09:00');
        $this->envoye('rdv', 'Rendez-vous', '2026-10-31 09:00');

        $premiere = $this->lire('rappels/envoyes', ['per_page' => 2]);
        $this->assertSame(['vaccin_retard', 'rdv'], array_column($premiere['data'], 'objet'));
        $this->assertSame(3, $premiere['meta']['total']);
        $this->assertSame(['id' => $this->patient->id, 'nom' => 'Félix'], $premiere['data'][0]['patient']);

        // Les vaccins comptent leurs relances
        $vaccins = $this->lire('rappels/envoyes', ['type' => 'vaccin']);
        $this->assertSame(['vaccin_retard', 'vaccin'], array_column($vaccins['data'], 'objet'));
    }
}
