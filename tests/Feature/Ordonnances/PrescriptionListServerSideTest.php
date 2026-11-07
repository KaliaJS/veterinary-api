<?php

namespace Tests\Feature\Ordonnances;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\DemandeRenouvellementOrdonnance;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Tri au-delà de 100 lignes (feu vert de Jim, 06.11.2026) : l'écran
 * Ordonnances demande au serveur le tri, le filtre et la recherche ; ils
 * portent sur tout le registre de l'organisation, page après page. Case de
 * notes/todo.md (dépôt web) : « Devis, Ordonnances et Imagerie : le tri
 * s'applique à toutes les lignes, pas seulement aux 100 premières
 * chargées ».
 */
class PrescriptionListServerSideTest extends TestCase
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

    private function ordonnance(string $animal, int $jours, array $attributs = [], string $medicament = 'Metacam', string $proprietaire = 'Borel', ?Clinique $clinique = null): Ordonnance
    {
        $clinique ??= $this->clinique;
        $organisation = $clinique->societe->organisation_id;
        $client = Client::factory()->create(['organisation_id' => $organisation, 'nom' => $proprietaire]);
        $patient = Patient::factory()->for($client)->create(['organisation_id' => $organisation, 'nom' => $animal]);
        $ordonnance = Ordonnance::factory()->create([
            'clinique_id' => $clinique->id,
            'patient_id' => $patient->id,
            'signee_le' => now()->subDays($jours)->toDateString(),
            ...$attributs,
        ]);
        OrdonnanceLigne::factory()->create([
            'ordonnance_id' => $ordonnance->id,
            'produit_id' => Produit::factory()->medicament()->create(['organisation_id' => $organisation, 'nom' => $medicament])->id,
        ]);

        return $ordonnance;
    }

    private function toutesLesPages(string $requete, int $parPage = 3): Collection
    {
        $lignes = collect();
        $page = 1;
        do {
            $reponse = $this->getJson("/api/v1/ordonnances?per_page={$parPage}&page={$page}&{$requete}", $this->enTetes($this->jeton))->assertOk();
            $lignes = $lignes->merge($reponse->json('data'));
            $derniere = $reponse->json('meta.last_page');
            $page++;
        } while ($page <= $derniere && $page < 50);

        return $lignes;
    }

    public function test_plus_anciennes_d_abord_sur_toutes_les_pages(): void
    {
        foreach (range(1, 8) as $i) {
            $this->ordonnance('Animal '.$i, $i * 2);
        }

        $dates = $this->toutesLesPages('tri=old')->pluck('signee_le')->all();

        $trie = $dates;
        sort($trie);
        $this->assertCount(8, $dates);
        $this->assertSame($trie, $dates);
        $this->assertSame(array_reverse($trie), $this->toutesLesPages('tri=recent')->pluck('signee_le')->all());
    }

    public function test_le_tri_par_patient_porte_sur_tout_le_registre(): void
    {
        $ids = [];
        foreach (['Pilou', 'anouk', 'Zéphyr', 'Bella', 'Moka', 'Iroise', 'Caramel'] as $i => $nom) {
            $ids[$nom] = $this->ordonnance($nom, $i + 1)->id;
        }

        $servies = $this->toutesLesPages('tri=patient')->pluck('id')->all();

        $this->assertSame([$ids['anouk'], $ids['Bella'], $ids['Caramel'], $ids['Iroise'], $ids['Moka'], $ids['Pilou'], $ids['Zéphyr']], $servies);
    }

    public function test_la_recherche_porte_sur_tout_le_registre_sans_tenir_compte_de_la_casse(): void
    {
        foreach (range(1, 7) as $i) {
            $this->ordonnance('Animal '.$i, $i);
        }
        $cerenia = $this->ordonnance('Pilou', 30, medicament: 'Cerenia 16 mg');
        $rochat = $this->ordonnance('Moka', 31, proprietaire: 'Rochat');

        $reponse = $this->getJson('/api/v1/ordonnances?per_page=3&q=CERENIA', $this->enTetes($this->jeton))->assertOk();
        $this->assertSame([$cerenia->id], collect($reponse->json('data'))->pluck('id')->all());
        // Le propriétaire et le numéro aussi
        $this->assertSame([$rochat->id], $this->toutesLesPages('q=rOCHAT')->pluck('id')->all());
        $this->assertSame([$cerenia->id], $this->toutesLesPages('q='.urlencode(strtolower($cerenia->numero)))->pluck('id')->all());
    }

    public function test_le_filtre_par_etat_porte_sur_tout_le_registre_et_garde_le_tri(): void
    {
        foreach (range(1, 6) as $i) {
            $this->ordonnance('Animal '.$i, $i);
        }
        $closes = collect([
            $this->ordonnance('Zoé', 20, ['statut' => 'stop']),
            $this->ordonnance('Arthur', 25, ['statut' => 'stop']),
        ]);

        $this->assertSame(['stop', 'stop'], $this->toutesLesPages('statut=stop')->pluck('statut')->all());
        $this->assertSame([$closes[1]->id, $closes[0]->id], $this->toutesLesPages('statut=stop&tri=patient')->pluck('id')->all());
    }

    public function test_les_compteurs_portent_sur_tout_le_registre(): void
    {
        foreach (range(1, 5) as $i) {
            $this->ordonnance('Animal '.$i, $i);
        }
        $this->ordonnance('Zoé', 20, ['statut' => 'stop']);
        $demandee = $this->ordonnance('Max', 21);
        DemandeRenouvellementOrdonnance::query()->create(['ordonnance_id' => $demandee->id, 'user_id' => User::factory()->create()->id]);
        // Une demande déjà traitée ne compte plus
        $traitee = $this->ordonnance('Nala', 22);
        DemandeRenouvellementOrdonnance::query()->create(['ordonnance_id' => $traitee->id, 'user_id' => User::factory()->create()->id, 'traitee_le' => now()]);

        foreach (['per_page=2', 'per_page=2&page=4', 'q=zoé', 'statut=stop'] as $requete) {
            $facettes = $this->getJson('/api/v1/ordonnances?'.$requete, $this->enTetes($this->jeton))->assertOk()->json('meta.facettes');
            $this->assertSame(['tous' => 8, 'active' => 7, 'stop' => 1, 'renouvellements' => 1], $facettes, $requete);
        }
    }

    public function test_une_valeur_inconnue_est_refusee(): void
    {
        $this->getJson('/api/v1/ordonnances?tri=montant', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('tri');
        $this->getJson('/api/v1/ordonnances?statut=perdue', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('statut');
    }

    public function test_une_autre_organisation_ne_sert_rien_meme_cherchee(): void
    {
        $this->ordonnance('Pilou', 1);
        $this->ordonnance('Pilou', 2, clinique: Clinique::factory()->create());

        foreach (['q=pilou', 'tri=patient', 'tri=old'] as $requete) {
            $reponse = $this->getJson('/api/v1/ordonnances?'.$requete, $this->enTetes($this->jeton))->assertOk();
            $this->assertCount(1, $reponse->json('data'), $requete);
            $this->assertSame(1, $reponse->json('meta.facettes.tous'));
        }
    }

    public function test_autant_de_requetes_pour_trois_ordonnances_que_pour_dix(): void
    {
        $compter = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/ordonnances?per_page=100&tri=patient&q=a', $this->enTetes($this->jeton))->assertOk();
            // Sans la mise à jour du jeton (« dernière utilisation »), faite ou non selon l'heure
            $n = count(array_filter(DB::getQueryLog(), fn (array $q): bool => ! str_contains($q['query'], 'personal_access_tokens')));
            DB::disableQueryLog();

            return $n;
        };
        foreach (range(1, 3) as $i) {
            $this->ordonnance('Animal '.$i, $i);
        }
        // Une première demande ouvre la session (jeton, site), puis on compte
        $compter();
        $avec3 = $compter();
        foreach (range(4, 10) as $i) {
            $this->ordonnance('Animal '.$i, $i);
        }

        $this->assertSame($avec3, $compter());
    }

    /** Vérification du 07.11.2026 : le tri par date de l'organisation a son index */
    public function test_le_tri_par_date_de_l_organisation_a_son_index(): void
    {
        $this->assertTrue(Schema::hasIndex('ordonnances', ['organisation_id', 'signee_le']));
    }
}
