<?php

namespace Tests\Feature\Stupefiants;

use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le registre des stupéfiants relève du domaine stocks, vérifié en base
 * à chaque requête : sans le domaine, journal, création, soldes et
 * export refusent en 403.
 */
class StupefiantsPermissionsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Produit $substance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->substance = Produit::factory()->stupefiant()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
        ]);
    }

    public function test_chaque_endpoint_du_registre_refuse_un_employe_sans_role(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);

        foreach ($this->appelsDuRegistre() as [$methode, $uri, $charge]) {
            $reponse = $methode === 'getJson'
                ? $this->getJson($uri, $this->enTetes($jeton))
                : $this->{$methode}($uri, $charge, $this->enTetes($jeton));

            $reponse->assertForbidden();
        }
    }

    public function test_une_derogation_retirant_le_domaine_stocks_ferme_le_registre(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Stocks,
            'est_accordee' => false,
        ]);

        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/stupefiants/mouvements', $entetes)->assertForbidden();
        $this->getJson('/api/v1/stupefiants/soldes', $entetes)->assertForbidden();
        $this->getJson('/api/v1/stupefiants/registre', $entetes)->assertForbidden();

        // Le domaine planning, lui, reste ouvert.
        $this->getJson('/api/v1/rendez-vous', $entetes)->assertOk();
    }

    public function test_un_role_avec_le_domaine_stocks_accede_au_registre(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/stupefiants/mouvements', $entetes)->assertOk();
        $this->getJson('/api/v1/stupefiants/soldes', $entetes)->assertOk();
        $this->getJson('/api/v1/stupefiants/registre', $entetes)->assertOk();
        $this->postJson('/api/v1/stupefiants/mouvements', [
            'produit_id' => $this->substance->id,
            'type' => 'reception',
            'fournisseur' => 'Grossiste',
            'quantite' => 10,
            'bulletin' => 'BL-1',
            'bulletin_le' => now('Europe/Zurich')->toDateString(),
        ], $entetes)->assertCreated();
    }

    /**
     * Chaque endpoint du registre avec une charge qui passe la
     * validation : l'autorisation (middleware can:stocks) vient avant
     * les contrôleurs.
     *
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function appelsDuRegistre(): array
    {
        return [
            ['getJson', '/api/v1/stupefiants/mouvements', []],
            ['postJson', '/api/v1/stupefiants/mouvements', ['produit_id' => $this->substance->id, 'quantite' => 5]],
            ['getJson', '/api/v1/stupefiants/soldes', []],
            ['getJson', '/api/v1/stupefiants/registre', []],
            ['getJson', '/api/v1/stupefiants/ordonnances?patient_id=1&produit_id='.$this->substance->id, []],
        ];
    }

    /**
     * Les ordonnances d'une remise disent quel animal suit quel traitement : le droit Stupéfiants (celui d'inscrire),
     * pas seulement Stocks, et seulement pour une substance du registre (vérification du 01.11.2026)
     */
    public function test_les_ordonnances_d_une_remise_demandent_le_droit_stupefiants_et_une_substance(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $autre = Produit::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->getJson('/api/v1/stupefiants/ordonnances?patient_id=1&produit_id='.$autre->id, $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('produit_id');

        DerogationPermission::create(['employe_id' => $employe->id, 'domaine' => DomainePermission::Stupefiants, 'est_accordee' => false]);
        $this->getJson('/api/v1/stupefiants/ordonnances?patient_id=1&produit_id='.$this->substance->id, $this->enTetes($jeton))->assertForbidden();
    }
}
