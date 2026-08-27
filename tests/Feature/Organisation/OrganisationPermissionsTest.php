<?php

namespace Tests\Feature\Organisation;

use App\Auth\RolesParDefaut;
use App\Enums\DomainePermission;
use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le domaine `organisation` (structure, abonnement SaaS) est réservé au
 * rôle qui le porte — l'Administrateur par défaut : vétérinaires, ASV
 * et assistants en sont exclus, dérogation possible.
 */
class OrganisationPermissionsTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
    }

    public function test_seul_l_administrateur_porte_le_domaine_organisation_par_defaut(): void
    {
        $roles = Role::withoutGlobalScopes()
            ->where('organisation_id', $this->clinique->societe->organisation_id)
            ->pluck('domaines', 'nom');

        $this->assertContains(DomainePermission::Organisation, $roles[RolesParDefaut::ADMINISTRATEUR]);
        $this->assertNotContains(DomainePermission::Organisation, $roles[RolesParDefaut::VETERINAIRE]);
        $this->assertNotContains(DomainePermission::Organisation, $roles[RolesParDefaut::ASV]);
        $this->assertNotContains(DomainePermission::Organisation, $roles[RolesParDefaut::ASSISTANT]);
    }

    public function test_chaque_endpoint_du_domaine_refuse_un_veterinaire(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $appels = [
            ['getJson', '/api/v1/organisation', []],
            ['getJson', '/api/v1/organisation/societes', []],
            ['postJson', '/api/v1/organisation/societes', ['nom' => 'Refusée']],
            ['getJson', '/api/v1/organisation/cliniques', []],
            ['postJson', '/api/v1/organisation/cliniques', []],
            ['patchJson', "/api/v1/organisation/cliniques/{$this->clinique->id}", []],
            ['deleteJson', "/api/v1/organisation/cliniques/{$this->clinique->id}", []],
            ['postJson', '/api/v1/organisation/abonnement', ['plan' => 'hopital']],
            ['postJson', '/api/v1/organisation/abonnement/resilier', []],
            ['postJson', '/api/v1/organisation/abonnement/reactiver', []],
            ['postJson', '/api/v1/organisation/packs', ['type' => 'sms', 'quantite' => 500]],
            ['putJson', '/api/v1/organisation/moyen-paiement', []],
            ['deleteJson', '/api/v1/organisation/moyen-paiement', []],
            ['getJson', '/api/v1/organisation/factures', []],
        ];

        foreach ($appels as [$methode, $uri, $charge]) {
            $reponse = $methode === 'getJson'
                ? $this->getJson($uri, $this->enTetes($jeton))
                : $this->{$methode}($uri, $charge, $this->enTetes($jeton));

            $reponse->assertForbidden();
        }
    }

    public function test_l_administrateur_accede_au_domaine(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $employe->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->clinique->societe->organisation_id)
                ->where('nom', RolesParDefaut::ADMINISTRATEUR)
                ->value('id'),
        ]);

        $this->getJson('/api/v1/organisation', $this->enTetes($jeton))->assertOk();
        $this->getJson('/api/v1/organisation/cliniques', $this->enTetes($jeton))->assertOk();
        $this->getJson('/api/v1/organisation/factures', $this->enTetes($jeton))->assertOk();
    }

    public function test_une_derogation_accorde_le_domaine_a_un_veterinaire(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/organisation', $this->enTetes($jeton))->assertForbidden();

        DerogationPermission::create([
            'employe_id' => $employe->id,
            'domaine' => DomainePermission::Organisation,
            'est_accordee' => true,
        ]);

        $this->getJson('/api/v1/organisation', $this->enTetes($jeton))->assertOk();
    }
}
