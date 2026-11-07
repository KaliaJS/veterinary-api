<?php

namespace Tests\Feature\Facturation;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Tri au-delà de 100 lignes (feu vert de Jim, 06.11.2026) : l'écran Devis
 * demande au serveur le tri, le filtre et la recherche ; ils portent sur
 * tous les devis de l'organisation, page après page, pas seulement sur les
 * premiers chargés. Case de notes/todo.md (dépôt web) : « Devis,
 * Ordonnances et Imagerie : le tri s'applique à toutes les lignes, pas
 * seulement aux 100 premières chargées ».
 */
class QuoteListServerSideTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 7)->setTime(10, 0));
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function organisation(): int
    {
        return $this->clinique->societe->organisation_id;
    }

    /** Un devis d'un animal nommé, émis il y a `$jours` jours, avec ses lignes */
    private function devis(string $animal, int $jours, array $prix = [100], array $attributs = [], string $proprietaire = 'Borel'): Devis
    {
        $client = Client::factory()->create(['organisation_id' => $this->organisation(), 'nom' => $proprietaire]);
        $patient = Patient::factory()->for($client)->create(['organisation_id' => $this->organisation(), 'nom' => $animal]);
        $devis = Devis::factory()->create([
            'organisation_id' => $this->organisation(),
            'patient_id' => $patient->id,
            'emis_le' => now()->subDays($jours)->toDateString(),
            ...$attributs,
        ]);
        foreach ($prix as $p) {
            DevisLigne::factory()->create(['devis_id' => $devis->id, 'quantite' => 1, 'prix_chf' => $p]);
        }

        return $devis;
    }

    /** @return array<string, mixed> */
    private function envoye(): array
    {
        return ['statut' => 'envoye', 'canal' => 'papier', 'valide_jusquau' => now()->addDays(30)->toDateString()];
    }

    /** @return array<string, mixed> */
    private function expire(): array
    {
        return ['statut' => 'envoye', 'canal' => 'papier', 'valide_jusquau' => now()->subDays(5)->toDateString()];
    }

    /** @return array<string, mixed> */
    private function accepte(): array
    {
        return ['statut' => 'accepte', 'canal' => 'papier', 'valide_jusquau' => now()->addDays(28)->toDateString(), 'signe_le' => now()->subDay(), 'signe_par' => 'Signature papier'];
    }

    /** Toutes les pages d'une demande, dans l'ordre servi */
    private function toutesLesPages(string $requete, int $parPage = 4): Collection
    {
        $lignes = collect();
        $page = 1;
        do {
            $reponse = $this->getJson("/api/v1/facturation/devis?per_page={$parPage}&page={$page}&{$requete}", $this->enTetes($this->jeton))->assertOk();
            $lignes = $lignes->merge($reponse->json('data'));
            $derniere = $reponse->json('meta.last_page');
            $page++;
        } while ($page <= $derniere && $page < 50);

        return $lignes;
    }

    public function test_plus_anciens_d_abord_sur_toutes_les_pages(): void
    {
        foreach (range(1, 10) as $i) {
            $this->devis('Animal '.$i, $i * 3);
        }

        $dates = $this->toutesLesPages('tri=old')->pluck('emis_le')->all();

        $this->assertCount(10, $dates);
        $trie = $dates;
        sort($trie);
        $this->assertSame($trie, $dates);
        // « Plus récents » : l'inverse, sur toutes les pages aussi
        $this->assertSame(array_reverse($trie), $this->toutesLesPages('tri=recent')->pluck('emis_le')->all());
    }

    public function test_le_tri_par_patient_range_tous_les_devis_par_nom(): void
    {
        foreach (['Pilou', 'anouk', 'Zéphyr', 'Bella', 'Moka', 'Iroise', 'Caramel', 'Dune', 'Elfe'] as $i => $nom) {
            $this->devis($nom, $i + 1);
        }

        $noms = $this->toutesLesPages('tri=patient')->pluck('patient.nom')->all();

        $this->assertCount(9, $noms);
        $attendu = $noms;
        usort($attendu, fn (string $a, string $b): int => strcmp(mb_strtolower($a), mb_strtolower($b)));
        $this->assertSame($attendu, $noms);
        $this->assertSame('anouk', $noms[0]);
    }

    public function test_le_tri_par_montant_suit_le_total_servi(): void
    {
        // Le total servi est le TTC après remise : 1000 avec 50 % de remise passe après 600
        $this->devis('A', 1, [1000], ['remise_pct' => 50]);
        $this->devis('B', 2, [600]);
        $this->devis('C', 3, [120, 80]);
        $this->devis('D', 4, [450, 90]);
        $this->devis('E', 5, [30]);
        $this->devis('F', 6, [299.95]);

        $totaux = $this->toutesLesPages('tri=amount', 2)->pluck('total_ttc')->map(fn ($t) => (float) $t)->all();

        $this->assertSame([600.0, 540.0, 500.0, 299.95, 200.0, 30.0], $totaux);
    }

    public function test_la_recherche_trouve_un_devis_au_dela_de_la_premiere_page_sans_tenir_compte_de_la_casse(): void
    {
        foreach (range(1, 12) as $i) {
            $this->devis('Animal '.$i, $i, attributs: ['motif' => 'Détartrage']);
        }
        $vieux = $this->devis('Pilou', 40, attributs: ['motif' => 'Exérèse d’un kyste']);
        $proprietaire = $this->devis('Moka', 41, attributs: ['motif' => 'Vaccins'], proprietaire: 'Rochat');

        $reponse = $this->getJson('/api/v1/facturation/devis?per_page=4&q=KYSTE', $this->enTetes($this->jeton))->assertOk();
        $this->assertSame([$vieux->id], collect($reponse->json('data'))->pluck('id')->all());
        $this->assertSame(1, $reponse->json('meta.total'));
        // Le patient, le numéro et le propriétaire aussi
        $this->assertSame([$vieux->id], collect($this->getJson('/api/v1/facturation/devis?q=piLOU', $this->enTetes($this->jeton))->json('data'))->pluck('id')->all());
        $this->assertSame([$vieux->id], collect($this->getJson('/api/v1/facturation/devis?q='.urlencode(strtolower($vieux->numero)), $this->enTetes($this->jeton))->json('data'))->pluck('id')->all());
        $this->assertSame([$proprietaire->id], collect($this->getJson('/api/v1/facturation/devis?q=rochat', $this->enTetes($this->jeton))->json('data'))->pluck('id')->all());
    }

    public function test_le_filtre_par_statut_porte_sur_tous_les_devis_et_garde_le_tri(): void
    {
        foreach (range(1, 9) as $i) {
            $this->devis('Animal '.$i, $i);
        }
        $acceptes = collect([
            $this->devis('Zoé', 20, [50], $this->accepte()),
            $this->devis('Arthur', 30, [900], $this->accepte()),
            $this->devis('Max', 25, [300], $this->accepte()),
        ]);
        $expire = $this->devis('Nala', 35, [100], $this->expire());

        $this->assertSame(['Arthur', 'Max', 'Zoé'], $this->toutesLesPages('statut=accepte&tri=patient', 2)->pluck('patient.nom')->all());
        $this->assertSame($acceptes->sortByDesc(fn (Devis $d) => $d->totalTtc())->pluck('id')->values()->all(), $this->toutesLesPages('statut=accepte&tri=amount', 2)->pluck('id')->all());
        $this->assertSame([$expire->id], $this->toutesLesPages('statut=expire')->pluck('id')->all());
    }

    public function test_les_compteurs_des_filtres_portent_sur_tous_les_devis(): void
    {
        foreach (range(1, 6) as $i) {
            $this->devis('Animal '.$i, $i);
        }
        $this->devis('Zoé', 20, [50], $this->envoye());
        $this->devis('Max', 21, [50], $this->envoye());
        $this->devis('Nala', 22, [50], $this->expire());
        $this->devis('Arthur', 23, [50], $this->accepte());

        // Les mêmes compteurs quelle que soit la page, la recherche ou le filtre choisi
        foreach (['per_page=2', 'per_page=2&page=3', 'q=zoé', 'statut=accepte'] as $requete) {
            $facettes = $this->getJson('/api/v1/facturation/devis?'.$requete, $this->enTetes($this->jeton))->assertOk()->json('meta.facettes');
            $this->assertSame(['tous' => 10, 'brouillon' => 6, 'envoye' => 2, 'accepte' => 1, 'refuse' => 0, 'expire' => 1], $facettes, $requete);
        }
    }

    public function test_une_valeur_inconnue_de_tri_ou_de_statut_est_refusee(): void
    {
        $this->getJson('/api/v1/facturation/devis?tri=prix', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('tri');
        $this->getJson('/api/v1/facturation/devis?statut=perdu', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('statut');
        $this->getJson('/api/v1/facturation/devis?q='.str_repeat('a', 101), $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_une_autre_organisation_ne_sert_rien_meme_cherchee(): void
    {
        $this->devis('Pilou', 1);
        $ailleurs = Clinique::factory()->create();
        $client = Client::factory()->create(['organisation_id' => $ailleurs->societe->organisation_id, 'nom' => 'Borel']);
        $patient = Patient::factory()->for($client)->create(['organisation_id' => $client->organisation_id, 'nom' => 'Pilou']);
        Devis::factory()->create(['organisation_id' => $client->organisation_id, 'patient_id' => $patient->id]);

        foreach (['q=pilou', 'tri=patient', 'tri=amount'] as $requete) {
            $reponse = $this->getJson('/api/v1/facturation/devis?'.$requete, $this->enTetes($this->jeton))->assertOk();
            $this->assertCount(1, $reponse->json('data'), $requete);
            $this->assertSame(1, $reponse->json('meta.facettes.tous'));
        }
    }

    public function test_autant_de_requetes_pour_trois_devis_que_pour_douze(): void
    {
        $compter = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/facturation/devis?per_page=100&tri=amount&q=a', $this->enTetes($this->jeton))->assertOk();
            // Sans la mise à jour du jeton (« dernière utilisation »), faite ou non selon l'heure
            $n = count(array_filter(DB::getQueryLog(), fn (array $q): bool => ! str_contains($q['query'], 'personal_access_tokens')));
            DB::disableQueryLog();

            return $n;
        };
        foreach (range(1, 3) as $i) {
            $this->devis('Animal '.$i, $i, [100, 50]);
        }
        // Une première demande ouvre la session (jeton, site), puis on compte
        $compter();
        $avec3 = $compter();
        foreach (range(4, 12) as $i) {
            $this->devis('Animal '.$i, $i, [100, 50]);
        }

        $this->assertSame($avec3, $compter());
    }

    /** Vérification du 07.11.2026 : le tri par date de l'organisation a son index */
    public function test_le_tri_par_date_de_l_organisation_a_son_index(): void
    {
        $this->assertTrue(Schema::hasIndex('devis', ['organisation_id', 'emis_le']));
    }
}
