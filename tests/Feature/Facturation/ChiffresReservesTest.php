<?php

namespace Tests\Feature\Facturation;

use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Facture;
use App\Models\Produit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le chiffre d'affaires et la marge de l'organisation (audit C11, Jim,
 * 14.11.2026) : réservés à l'administrateur et au droit « Voir les
 * chiffres » (le rôle Manager). Les chiffres de la boutique ne comptent
 * que le comptoir ; dans la caisse, l'encaissé du jour et les factures en
 * attente restent à qui a la facturation.
 */
class ChiffresReservesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Produit $vaccin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00', 'Europe/Zurich'));
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        $this->vaccin = Produit::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'categorie' => 'ali',
            'est_boutique' => true,
            'est_sur_ordonnance' => false,
            'prix' => 30,
            'cout' => 10,
        ]);
    }

    public function test_les_chiffres_de_la_boutique_ne_comptent_que_le_comptoir(): void
    {
        $comptoir = $this->facturePayee(venteComptoir: true, prix: 30);
        // Une consultation payée, avec le même produit : ce n'est pas une vente de la boutique
        $this->facturePayee(venteComptoir: false, prix: 50);
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');

        $data = $this->getJson('/api/v1/boutique/statistiques', $this->enTetes($jeton))->assertOk()->json('data');

        $this->assertEquals(30, $data['totaux']['chiffre_affaires']);
        $this->assertSame(1, $data['totaux']['tickets']);
        $this->assertSame([$comptoir->id], array_column($data['ventes'], 'facture_id'));
    }

    public function test_les_chiffres_de_la_boutique_demandent_voir_les_chiffres_ou_l_administration(): void
    {
        foreach (['assistant', 'asv', null] as $role) {
            ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], $role);

            $this->getJson('/api/v1/boutique/statistiques', $this->enTetes($jeton))->assertForbidden();
        }

        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');
        $this->getJson('/api/v1/boutique/statistiques', $this->enTetes($jeton))->assertOk();

        // À titre personnel
        ['employe' => $veterinaire, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $veterinaire->id, 'domaine' => DomainePermission::VoirChiffres, 'est_accordee' => true]);
        $this->getJson('/api/v1/boutique/statistiques', $this->enTetes($jeton))->assertOk();

        // L'administrateur les voit, même sans le droit lui-même
        ['employe' => $administrateur, 'jeton' => $jeton] = $this->administrateurAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $administrateur->id, 'domaine' => DomainePermission::VoirChiffres, 'est_accordee' => false]);
        $this->getJson('/api/v1/boutique/statistiques', $this->enTetes($jeton))->assertOk();
    }

    public function test_le_chiffre_du_mois_de_la_caisse_demande_voir_les_chiffres(): void
    {
        $this->facturePayee(venteComptoir: false, prix: 50);

        foreach (['asv', null] as $role) {
            ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], $role);

            $data = $this->getJson('/api/v1/facturation/statistiques', $this->enTetes($jeton))->assertOk()->json('data');
            // L'encaissé du jour et les factures en attente : la caisse en a besoin pour sa clôture
            $this->assertArrayHasKey('du_jour', $data);
            $this->assertArrayHasKey('en_attente', $data);
            $this->assertArrayNotHasKey('mois', $data);
        }

        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');
        $this->getJson('/api/v1/facturation/statistiques', $this->enTetes($jeton))->assertOk()
            ->assertJsonPath('data.mois.ca_par_clinique.0.total', 50);
    }

    public function test_les_statistiques_de_la_caisse_sur_une_periode_demandent_voir_les_chiffres(): void
    {
        $periode = '?'.http_build_query(['from' => '2026-11-01', 'to' => '2026-11-14', 'compare' => 'none', 'history' => 0]);

        foreach (['asv', null] as $role) {
            ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], $role);

            $this->getJson('/api/v1/facturation/statistics'.$periode, $this->enTetes($jeton))->assertForbidden();
        }

        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');
        $this->getJson('/api/v1/facturation/statistics'.$periode, $this->enTetes($jeton))->assertOk();
    }

    /**
     * Les exports pour la fiduciaire contiennent les totaux du mois (Jim,
     * 14.11.2026, choix A) : journal des ventes et des encaissements, TVA,
     * écritures comptables, factures ouvertes à une date, et le mois remis.
     */
    public function test_les_exports_pour_la_fiduciaire_demandent_voir_les_chiffres(): void
    {
        $exports = [
            ['getJson', '/api/v1/facturation/factures/journal?mois=2026-11'],
            ['getJson', '/api/v1/facturation/factures/ouvertes?date=2026-11-14'],
            ['getJson', '/api/v1/facturation/ecritures?mois=2026-10'],
            ['postJson', '/api/v1/facturation/ecritures/2026-10/verrou'],
            ['deleteJson', '/api/v1/facturation/ecritures/2026-10/verrou'],
        ];

        foreach (['asv', null] as $role) {
            ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], $role);
            foreach ($exports as [$methode, $uri]) {
                $this->appel($methode, $uri, $jeton)->assertForbidden();
            }
        }

        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'manager');
        foreach (array_slice($exports, 0, 3) as [$methode, $uri]) {
            $this->appel($methode, $uri, $jeton)->assertOk();
        }

        // Le mois remis : « Clôturer la caisse » ne suffit pas sans « Voir les chiffres », et l'inverse non plus
        ['employe' => $asvEnChef, 'jeton' => $jetonAsv] = $this->employeAvecJeton($this->clinique, [], 'asv');
        DerogationPermission::create(['employe_id' => $asvEnChef->id, 'domaine' => DomainePermission::CloturerCaisse, 'est_accordee' => true]);
        ['employe' => $manager, 'jeton' => $jetonManager] = $this->employeAvecJeton($this->clinique, [], 'manager');
        foreach (array_slice($exports, 3) as [$methode, $uri]) {
            $this->appel($methode, $uri, $jetonAsv)->assertForbidden();
            $this->appel($methode, $uri, $jetonManager)->assertForbidden();
        }
        DerogationPermission::create(['employe_id' => $manager->id, 'domaine' => DomainePermission::CloturerCaisse, 'est_accordee' => true]);
        $this->appel('postJson', '/api/v1/facturation/ecritures/2026-10/verrou', $jetonManager)->assertSuccessful();
        $this->appel('deleteJson', '/api/v1/facturation/ecritures/2026-10/verrou', $jetonManager)->assertSuccessful();
    }

    private function appel(string $methode, string $uri, string $jeton): TestResponse
    {
        return $methode === 'getJson' ? $this->getJson($uri, $this->enTetes($jeton)) : $this->{$methode}($uri, [], $this->enTetes($jeton));
    }

    private function facturePayee(bool $venteComptoir, float $prix): Facture
    {
        $facture = Facture::factory()->payee()->create([
            'clinique_id' => $this->clinique->id,
            'payee_le' => CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Zurich')->utc(),
            'vente_comptoir' => $venteComptoir,
        ]);
        $facture->lignes()->create(['produit_id' => $this->vaccin->id, 'libelle' => $this->vaccin->nom, 'quantite' => 1, 'prix_chf' => $prix, 'type' => 'med']);

        return $facture;
    }
}
