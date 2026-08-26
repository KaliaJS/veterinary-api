<?php

namespace Tests\Feature;

use App\Auth\ContexteClinique;
use App\Auth\EmetteurJetons;
use App\Enums\TypeSalle;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Salle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Isolation multi-clinique : un jeton d'une clinique ne lit ni ne
 * modifie jamais les données d'une autre — appliquée par le scope
 * global du contexte, pas par les contrôleurs.
 */
class IsolationCliniqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $cliniqueA;

    private Clinique $cliniqueB;

    private Salle $salleA;

    private Salle $salleB;

    private string $jetonA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliniqueA = Clinique::factory()->create();
        $this->cliniqueB = Clinique::factory()->create();
        $this->salleA = Salle::factory()->create(['clinique_id' => $this->cliniqueA->id, 'nom' => 'Consultation A']);
        $this->salleB = Salle::factory()->create(['clinique_id' => $this->cliniqueB->id, 'nom' => 'Consultation B']);

        ['jeton' => $this->jetonA] = $this->employeAvecJeton($this->cliniqueA);
    }

    public function test_la_liste_ne_montre_que_la_clinique_du_jeton(): void
    {
        $this->getJson('/api/v1/salles', $this->enTetes($this->jetonA))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->salleA->id);
    }

    public function test_lire_une_ressource_d_une_autre_clinique_repond_introuvable(): void
    {
        $this->getJson("/api/v1/salles/{$this->salleB->id}", $this->enTetes($this->jetonA))
            ->assertNotFound();
    }

    public function test_modifier_une_ressource_d_une_autre_clinique_repond_introuvable(): void
    {
        $this->patchJson(
            "/api/v1/salles/{$this->salleB->id}",
            ['nom' => 'Piratée'],
            $this->enTetes($this->jetonA),
        )->assertNotFound();

        $this->assertSame(
            'Consultation B',
            Salle::withoutGlobalScopes()->find($this->salleB->id)->nom,
        );
    }

    public function test_une_creation_est_rattachee_a_la_clinique_du_jeton(): void
    {
        $reponse = $this->postJson('/api/v1/salles', [
            'nom' => 'Salle de soins',
            'type' => TypeSalle::Consultation->value,
        ], $this->enTetes($this->jetonA))->assertCreated();

        $salle = Salle::withoutGlobalScopes()->find($reponse->json('data.id'));

        $this->assertSame($this->cliniqueA->id, $salle->clinique_id);
    }

    public function test_forcer_une_autre_clinique_a_l_ecriture_est_refuse(): void
    {
        // `clinique_id` n'est pas un champ accepté par la validation ;
        // même injecté de force dans le modèle, l'écriture est bloquée.
        $this->postJson('/api/v1/salles', [
            'nom' => 'Intrusion',
            'type' => TypeSalle::Bloc->value,
            'clinique_id' => $this->cliniqueB->id,
        ], $this->enTetes($this->jetonA))->assertCreated();

        $this->assertSame(
            0,
            Salle::withoutGlobalScopes()->where('clinique_id', $this->cliniqueB->id)->where('nom', 'Intrusion')->count(),
        );
    }

    public function test_un_jeton_ne_peut_pas_etre_emis_pour_une_clinique_etrangere(): void
    {
        $this->postJson('/api/v1/auth/rafraichir', [], $this->enTetes($this->jetonA))->assertOk();

        // Émettre pour une clinique où l'utilisateur n'est pas employé
        // échoue dès l'émission (couvert aussi côté login).
        $autre = $this->employeAvecJeton($this->cliniqueB);
        $this->expectExceptionMessage('Aucun rattachement à cette clinique.');
        app(EmetteurJetons::class)->emettre($autre['user'], $this->cliniqueA->id);
    }

    public function test_les_donnees_d_organisation_sont_scopees_aussi(): void
    {
        // Deux organisations distinctes : un patient chez chacune.
        $orgA = $this->cliniqueA->societe->organisation_id;
        $clientA = Client::factory()->create(['organisation_id' => $orgA]);
        $patientA = Patient::factory()->create(['organisation_id' => $orgA, 'client_id' => $clientA->id]);

        $orgB = $this->cliniqueB->societe->organisation_id;
        $clientB = Client::factory()->create(['organisation_id' => $orgB]);
        Patient::factory()->create(['organisation_id' => $orgB, 'client_id' => $clientB->id]);

        // Contexte de la clinique A : seuls les patients de son
        // organisation sont visibles.
        app(ContexteClinique::class)->definir($this->cliniqueA);

        $this->assertSame([$patientA->id], Patient::pluck('id')->all());
        $this->assertSame([$clientA->id], Client::pluck('id')->all());

        app(ContexteClinique::class)->oublier();
        $this->assertSame(2, Patient::count());
    }

    public function test_les_roles_sont_isoles_par_organisation(): void
    {
        // Chaque organisation a ses quatre rôles par défaut ; sous le
        // contexte de la clinique A, seuls les siens existent.
        app(ContexteClinique::class)->definir($this->cliniqueA);

        $this->assertSame(4, Role::count());
        $this->assertSame(
            [$this->cliniqueA->societe->organisation_id],
            Role::pluck('organisation_id')->unique()->values()->all(),
        );

        app(ContexteClinique::class)->oublier();
        $this->assertSame(8, Role::count());
    }

    public function test_un_rattachement_revoque_coupe_l_acces(): void
    {
        ['employe' => $employe, 'jeton' => $jeton] = $this->employeAvecJeton($this->cliniqueB);

        $this->getJson('/api/v1/salles', $this->enTetes($jeton))->assertOk();

        $employe->delete();

        $this->getJson('/api/v1/salles', $this->enTetes($jeton))->assertForbidden();
    }
}
