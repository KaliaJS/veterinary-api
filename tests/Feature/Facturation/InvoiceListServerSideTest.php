<?php

namespace Tests\Feature\Facturation;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * L'onglet « Factures et devis » du dossier patient (case de notes/todo.md,
 * dépôt web : « Les filtres et la recherche des onglets portent sur toutes
 * les lignes, pas seulement les pages lues ; factures et devis au-delà de
 * 100 ») : les factures de l'animal se cherchent, se filtrent et se trient
 * côté serveur, page après page.
 */
class InvoiceListServerSideTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $bourbon;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 7)->setTime(10, 0));
        $this->clinique = Clinique::factory()->create();
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->bourbon = Patient::factory()->for($client)->create(['organisation_id' => $client->organisation_id, 'nom' => 'Bourbon']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function facture(int $jours, array $prix = [100], array $attributs = [], string $libelle = 'Consultation standard'): Facture
    {
        $facture = Facture::factory()->create([
            'clinique_id' => $this->clinique->id,
            'patient_id' => $this->bourbon->id,
            'client_id' => $this->bourbon->client_id,
            'emise_le' => now()->subDays($jours),
            ...$attributs,
        ]);
        foreach ($prix as $p) {
            FactureLigne::factory()->create(['facture_id' => $facture->id, 'libelle' => $libelle, 'quantite' => 1, 'prix_chf' => $p]);
        }

        return $facture;
    }

    private function toutesLesPages(string $requete, int $parPage = 3): Collection
    {
        $lignes = collect();
        $page = 1;
        do {
            $reponse = $this->getJson("/api/v1/facturation/factures?patient_id={$this->bourbon->id}&per_page={$parPage}&page={$page}&{$requete}", $this->enTetes($this->jeton))->assertOk();
            $lignes = $lignes->merge($reponse->json('data'));
            $derniere = $reponse->json('meta.last_page');
            $page++;
        } while ($page <= $derniere && $page < 50);

        return $lignes;
    }

    public function test_la_recherche_trouve_une_facture_au_dela_de_la_premiere_page_sans_tenir_compte_de_la_casse(): void
    {
        foreach (range(1, 8) as $i) {
            $this->facture($i);
        }
        $detartrage = $this->facture(60, [180], libelle: 'Détartrage sous anesthésie');

        $this->assertSame([$detartrage->id], $this->toutesLesPages('q='.urlencode('DÉTARTRAGE'))->pluck('id')->all());
        $this->assertSame([$detartrage->id], $this->toutesLesPages('q='.urlencode(strtolower($detartrage->numero)))->pluck('id')->all());
    }

    public function test_a_regler_garde_les_factures_dues_dont_il_reste_quelque_chose(): void
    {
        foreach (range(1, 5) as $i) {
            $this->facture($i, attributs: ['statut' => 'payee', 'payee_le' => now()->subDays($i), 'moyen_paiement' => 'cash']);
        }
        $due = $this->facture(30, [120]);
        // Due, mais l'acompte payé en ligne couvre tout
        $this->facture(31, [50], ['acompte' => 50]);

        $this->assertSame([$due->id], $this->toutesLesPages('a_regler=1')->pluck('id')->all());
    }

    public function test_les_tris_portent_sur_toutes_les_pages(): void
    {
        $a = $this->facture(1, [40]);
        $b = $this->facture(2, [400]);
        $c = $this->facture(3, [120, 30]);
        $d = $this->facture(4, [90]);

        $this->assertSame([$d->id, $c->id, $b->id, $a->id], $this->toutesLesPages('tri=old')->pluck('id')->all());
        $this->assertSame([$a->id, $b->id, $c->id, $d->id], $this->toutesLesPages('tri=recent')->pluck('id')->all());
        $this->assertSame([$b->id, $c->id, $d->id, $a->id], $this->toutesLesPages('tri=amount')->pluck('id')->all());
    }

    public function test_une_valeur_inconnue_est_refusee(): void
    {
        $this->getJson('/api/v1/facturation/factures?tri=patient', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('tri');
        $this->getJson('/api/v1/facturation/factures?statut=perdue', $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('statut');
    }

    public function test_une_autre_organisation_ne_sert_rien_meme_cherchee(): void
    {
        $sienne = $this->facture(1, libelle: 'Vaccination');
        $ailleurs = Clinique::factory()->create();
        $facture = Facture::factory()->create(['clinique_id' => $ailleurs->id, 'patient_id' => null]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'libelle' => 'Vaccination']);

        $ids = collect($this->getJson('/api/v1/facturation/factures?q=vaccination', $this->enTetes($this->jeton))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$sienne->id], $ids);
    }
}
