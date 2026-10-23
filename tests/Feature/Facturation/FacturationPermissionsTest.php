<?php

namespace Tests\Feature\Facturation;

use App\Auth\RolesParDefaut;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\Employe;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La caisse est fermée aux rôles sans le droit `facturation` (par défaut
 * l'Assistant ; l'ASV encaisse depuis le 23.10.2026). Chaque endpoint du
 * domaine refuse en 403, alors que le reste de son métier (planning,
 * patients, stocks) reste ouvert.
 */
class FacturationPermissionsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Facture $facture;

    private Devis $devis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->devis = Devis::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'patient_id' => Patient::factory()
                ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
                ->create()
                ->id,
        ]);
    }

    /** @return array{employe: Employe, jeton: string} */
    private function assistantAvecJeton(): array
    {
        $resultat = $this->employeAvecJeton($this->clinique);

        $resultat['employe']->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->clinique->societe->organisation_id)
                ->where('nom', RolesParDefaut::ASSISTANT)
                ->value('id'),
        ]);

        return $resultat;
    }

    public function test_chaque_endpoint_de_facturation_refuse_un_role_sans_la_caisse(): void
    {
        ['jeton' => $jeton] = $this->assistantAvecJeton();

        foreach ($this->appelsDuDomaine() as [$methode, $uri, $charge]) {
            $reponse = $methode === 'getJson'
                ? $this->getJson($uri, $this->enTetes($jeton))
                : $this->{$methode}($uri, $charge, $this->enTetes($jeton));

            $reponse->assertForbidden();
        }
    }

    public function test_le_reste_du_metier_de_l_asv_reste_ouvert(): void
    {
        ['jeton' => $jeton] = $this->assistantAvecJeton();
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/rendez-vous', $entetes)->assertOk();
        $this->getJson('/api/v1/patients', $entetes)->assertOk();
        $this->getJson('/api/v1/ordonnances', $entetes)->assertOk();
        $this->getJson('/api/v1/stocks/produits', $entetes)->assertOk();
    }

    public function test_un_employe_sans_role_est_refuse_partout(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);

        $this->getJson('/api/v1/facturation/factures', $this->enTetes($jeton))->assertForbidden();
        $this->getJson('/api/v1/facturation/devis', $this->enTetes($jeton))->assertForbidden();
    }

    public function test_un_veterinaire_accede_au_domaine(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/facturation/factures', $entetes)->assertOk();
        $this->getJson('/api/v1/facturation/prestations', $entetes)->assertOk();
        $this->getJson('/api/v1/facturation/devis', $entetes)->assertOk();
        $this->getJson('/api/v1/facturation/clotures-caisse', $entetes)->assertOk();
        $this->getJson('/api/v1/facturation/statistiques', $entetes)->assertOk();
    }

    /**
     * Chaque endpoint du domaine — le middleware can:facturation refuse
     * avant toute validation.
     *
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function appelsDuDomaine(): array
    {
        $facture = $this->facture->id;
        $devis = $this->devis->id;

        return [
            ['getJson', '/api/v1/facturation/prestations', []],
            ['getJson', '/api/v1/facturation/statistiques', []],
            ['getJson', '/api/v1/facturation/factures', []],
            ['postJson', '/api/v1/facturation/factures', []],
            ['getJson', "/api/v1/facturation/factures/{$facture}", []],
            ['deleteJson', "/api/v1/facturation/factures/{$facture}", []],
            ['postJson', "/api/v1/facturation/factures/{$facture}/lignes", []],
            ['patchJson', "/api/v1/facturation/factures/{$facture}/lignes/1", ['quantite' => 1]],
            ['deleteJson', "/api/v1/facturation/factures/{$facture}/lignes/1", []],
            ['postJson', "/api/v1/facturation/factures/{$facture}/prescriptions", []],
            ['postJson', "/api/v1/facturation/factures/{$facture}/encaisser", []],
            ['postJson', "/api/v1/facturation/factures/{$facture}/payer-plus-tard", []],
            ['putJson', "/api/v1/facturation/factures/{$facture}/decompte", []],
            ['postJson', "/api/v1/facturation/factures/{$facture}/decompte/avancer", []],
            ['getJson', '/api/v1/facturation/clotures-caisse', []],
            ['postJson', '/api/v1/facturation/clotures-caisse', []],
            ['getJson', '/api/v1/facturation/devis', []],
            ['postJson', '/api/v1/facturation/devis', []],
            ['getJson', "/api/v1/facturation/devis/{$devis}", []],
            ['patchJson', "/api/v1/facturation/devis/{$devis}", []],
            ['deleteJson', "/api/v1/facturation/devis/{$devis}", []],
            ['postJson', "/api/v1/facturation/devis/{$devis}/envoyer", []],
            ['postJson', "/api/v1/facturation/devis/{$devis}/accepter", []],
            ['postJson', "/api/v1/facturation/devis/{$devis}/refuser", []],
            ['postJson', "/api/v1/facturation/devis/{$devis}/dupliquer", []],
            ['postJson', "/api/v1/facturation/devis/{$devis}/facturer-acompte", []],
            ['postJson', "/api/v1/facturation/devis/{$devis}/facturer-finale", []],
        ];
    }
}
