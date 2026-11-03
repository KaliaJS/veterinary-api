<?php

namespace Tests\Feature\Facturation;

use App\Enums\StatutFacture;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Facture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * « Encaisser » depuis le dossier d'un patient (03.11.2026) : la facture
 * due la plus récente de son propriétaire, demandée à l'API (filtrée sur
 * le propriétaire et les factures dues), même au-delà d'une page.
 */
class InvoicesByOwnerTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_les_factures_dues_d_un_proprietaire(): void
    {
        $clinique = Clinique::factory()->create();
        $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id]);
        $autre = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id]);
        ['jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        // Trente factures payées plus récentes que la due : elle n'est pas sur la première page du fil
        foreach (range(1, 30) as $i) {
            Facture::factory()->create(['clinique_id' => $clinique->id, 'client_id' => $client->id, 'statut' => StatutFacture::Payee, 'emise_le' => now()->subDays($i)]);
        }
        $due = Facture::factory()->create(['clinique_id' => $clinique->id, 'client_id' => $client->id, 'statut' => StatutFacture::Due, 'emise_le' => now()->subDays(40)]);
        Facture::factory()->create(['clinique_id' => $clinique->id, 'client_id' => $autre->id, 'statut' => StatutFacture::Due, 'emise_le' => now()->subDay()]);

        $page = $this->getJson("/api/v1/facturation/factures?client_id={$client->id}&statut=due&per_page=1", $this->enTetes($jeton))->assertOk();

        $this->assertSame([$due->id], collect($page->json('data'))->pluck('id')->all());
    }

    /** Point 3 : les factures dues de ce propriétaire dans la clinique en cours */
    public function test_les_factures_dues_dans_une_clinique(): void
    {
        $clinique = Clinique::factory()->create();
        $soeur = Clinique::factory()->create(['societe_id' => $clinique->societe_id]);
        $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id]);
        ['jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        Facture::factory()->create(['clinique_id' => $soeur->id, 'client_id' => $client->id, 'statut' => StatutFacture::Due, 'emise_le' => now()->subDay()]);

        $ici = $this->getJson("/api/v1/facturation/factures?client_id={$client->id}&statut=due&clinique_id={$clinique->id}", $this->enTetes($jeton))->assertOk();
        $this->assertSame([], $ici->json('data'));
        $partout = $this->getJson("/api/v1/facturation/factures?client_id={$client->id}&statut=due", $this->enTetes($jeton))->assertOk();
        $this->assertCount(1, $partout->json('data'));
    }
}
