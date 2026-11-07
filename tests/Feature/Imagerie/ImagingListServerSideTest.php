<?php

namespace Tests\Feature\Imagerie;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\ExamenImagerie;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Tri au-delà de 100 lignes (feu vert de Jim, 06.11.2026) : l'écran
 * Imagerie demande au serveur le tri, les filtres et la recherche ; ils
 * portent sur tous les examens de la clinique, page après page. Case de
 * notes/todo.md (dépôt web) : « Devis, Ordonnances et Imagerie : le tri
 * s'applique à toutes les lignes, pas seulement aux 100 premières
 * chargées ».
 */
class ImagingListServerSideTest extends TestCase
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

    private function examen(string $animal, int $jours, array $attributs = [], string $proprietaire = 'Borel', bool $estSigne = false, ?Clinique $clinique = null): ExamenImagerie
    {
        $clinique ??= $this->clinique;
        $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'nom' => $proprietaire]);
        $patient = Patient::factory()->for($client)->create(['organisation_id' => $client->organisation_id, 'nom' => $animal]);
        $prescripteur = Employe::factory()->dans($clinique)->create();

        return ExamenImagerie::factory()->create([
            'clinique_id' => $clinique->id,
            'patient_id' => $patient->id,
            'prescripteur_id' => $prescripteur->id,
            'realise_le' => now()->subDays($jours),
            ...($estSigne ? ['constatations' => 'Normal.', 'conclusion' => 'RAS', 'signe_par_id' => $prescripteur->id, 'signe_le' => now()->subDays($jours)->addHour()] : []),
            ...$attributs,
        ]);
    }

    private function toutesLesPages(string $requete, int $parPage = 3): Collection
    {
        $lignes = collect();
        $page = 1;
        do {
            $reponse = $this->getJson("/api/v1/imagerie/examens?per_page={$parPage}&page={$page}&{$requete}", $this->enTetes($this->jeton))->assertOk();
            $lignes = $lignes->merge($reponse->json('data'));
            $derniere = $reponse->json('meta.last_page');
            $page++;
        } while ($page <= $derniere && $page < 50);

        return $lignes;
    }

    public function test_a_interpreter_d_abord_puis_signes_chacun_du_plus_recent_au_plus_ancien(): void
    {
        $signe1 = $this->examen('A', 1, estSigne: true);
        $ouvert1 = $this->examen('B', 2);
        $signe2 = $this->examen('C', 3, estSigne: true);
        $ouvert2 = $this->examen('D', 40);
        $signe3 = $this->examen('E', 50, estSigne: true);
        $ouvert3 = $this->examen('F', 60);

        $this->assertSame(
            [$ouvert1->id, $ouvert2->id, $ouvert3->id, $signe1->id, $signe2->id, $signe3->id],
            $this->toutesLesPages('tri=todo', 2)->pluck('id')->all(),
        );
    }

    public function test_plus_anciens_et_patient_sur_toutes_les_pages(): void
    {
        $ids = [];
        foreach (['Pilou', 'anouk', 'Zéphyr', 'Bella', 'Moka', 'Iroise', 'Caramel'] as $i => $nom) {
            $ids[$nom] = $this->examen($nom, ($i + 1) * 5, estSigne: $i % 2 === 0)->id;
        }

        $this->assertSame([$ids['Caramel'], $ids['Iroise'], $ids['Moka'], $ids['Bella'], $ids['Zéphyr'], $ids['anouk'], $ids['Pilou']], $this->toutesLesPages('tri=old')->pluck('id')->all());
        $this->assertSame([$ids['Pilou'], $ids['anouk'], $ids['Zéphyr'], $ids['Bella'], $ids['Moka'], $ids['Iroise'], $ids['Caramel']], $this->toutesLesPages('tri=recent')->pluck('id')->all());
        $this->assertSame([$ids['anouk'], $ids['Bella'], $ids['Caramel'], $ids['Iroise'], $ids['Moka'], $ids['Pilou'], $ids['Zéphyr']], $this->toutesLesPages('tri=patient')->pluck('id')->all());
    }

    public function test_filtres_et_recherche_portent_sur_tous_les_examens_sans_tenir_compte_de_la_casse(): void
    {
        foreach (range(1, 7) as $i) {
            $this->examen('Animal '.$i, $i);
        }
        $echo = $this->examen('Pilou', 30, ['modalite' => 'US', 'libelle' => 'Échographie abdominale'], estSigne: true);
        $rochat = $this->examen('Moka', 31, ['libelle' => 'Thorax'], proprietaire: 'Rochat');

        $this->assertSame([$echo->id], $this->toutesLesPages('modalite=US')->pluck('id')->all());
        $this->assertSame([$echo->id], $this->toutesLesPages('statut=fait')->pluck('id')->all());
        $this->assertCount(8, $this->toutesLesPages('statut=todo'));
        $this->assertSame([$echo->id], $this->toutesLesPages('q=ABDOMINALE')->pluck('id')->all());
        $this->assertSame([$echo->id], $this->toutesLesPages('q=piLOU')->pluck('id')->all());
        $this->assertSame([$rochat->id], $this->toutesLesPages('q=rochat')->pluck('id')->all());
        $this->assertSame([], $this->toutesLesPages('q=rochat&modalite=US')->pluck('id')->all());
    }

    public function test_une_valeur_inconnue_est_refusee(): void
    {
        $this->getJson('/api/v1/imagerie/examens?tri=prix', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('tri');
        $this->getJson('/api/v1/imagerie/examens?modalite=IRM', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('modalite');
        $this->getJson('/api/v1/imagerie/examens?statut=perdu', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('statut');
    }

    public function test_les_examens_d_une_clinique_soeur_ou_etrangere_ne_sont_pas_servis(): void
    {
        $this->examen('Pilou', 1);
        $this->examen('Pilou', 2, clinique: Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]));
        $this->examen('Pilou', 3, clinique: Clinique::factory()->create());

        foreach (['q=pilou', 'tri=patient', 'tri=todo'] as $requete) {
            $this->assertCount(1, $this->toutesLesPages($requete), $requete);
        }
    }

    public function test_autant_de_requetes_pour_trois_examens_que_pour_dix(): void
    {
        $compter = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/imagerie/examens?per_page=100&tri=patient&q=a', $this->enTetes($this->jeton))->assertOk();
            // Sans la mise à jour du jeton (« dernière utilisation »), faite ou non selon l'heure
            $n = count(array_filter(DB::getQueryLog(), fn (array $q): bool => ! str_contains($q['query'], 'personal_access_tokens')));
            DB::disableQueryLog();

            return $n;
        };
        foreach (range(1, 3) as $i) {
            $this->examen('Animal '.$i, $i, estSigne: $i === 2);
        }
        // Une première demande ouvre la session (jeton, site), puis on compte
        $compter();
        $avec3 = $compter();
        foreach (range(4, 10) as $i) {
            $this->examen('Animal '.$i, $i, estSigne: true);
        }

        $this->assertSame($avec3, $compter());
    }
}
