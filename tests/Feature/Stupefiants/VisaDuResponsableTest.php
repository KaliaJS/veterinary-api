<?php

namespace Tests\Feature\Stupefiants;

use App\Models\Clinique;
use App\Models\DerogationPermission;
use App\Models\MouvementStupefiant;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le visa du registre des stupéfiants réservé au vétérinaire responsable
 * (décision de Jim, 01.11.2026) : un administrateur le désigne parmi les
 * vétérinaires de la clinique ; lui seul vise, toutes les substances tenues
 * d'un coup ; une ligne « visa » ne s'inscrit plus par le chemin général.
 */
class VisaDuResponsableTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->admin] = $this->administrateurAvecJeton($this->clinique);
        $organisation = $this->clinique->societe->organisation_id;
        foreach (['Méthadone 10 mg/ml', 'Kétamine 100 mg/ml'] as $nom) {
            Produit::factory()->stupefiant()->create(['organisation_id' => $organisation, 'nom' => $nom, 'tableau_stupefiant' => 'a']);
        }
    }

    private function recevoir(string $jeton): void
    {
        foreach (Produit::query()->where('est_stupefiant', true)->get() as $produit) {
            $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $produit->id, 'type' => 'reception', 'quantite' => 20, 'fournisseur' => 'Grossiste'], $this->enTetes($jeton))->assertCreated();
        }
    }

    public function test_seul_le_veterinaire_responsable_vise_le_registre(): void
    {
        ['employe' => $responsable, 'jeton' => $jetonResponsable] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Anna Meyer']);
        ['jeton' => $jetonAutre] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Paul Rochat']);
        $this->recevoir($jetonResponsable);

        // Personne n'est désigné : personne ne vise
        $this->postJson('/api/v1/stupefiants/visa', [], $this->enTetes($jetonResponsable))
            ->assertUnprocessable()->assertJsonValidationErrors(['visa' => 'Aucun vétérinaire responsable n’est désigné pour cette clinique : un administrateur le désigne dans l’onglet Stupéfiants.']);

        // Un administrateur le désigne ; il apparaît avec les vétérinaires candidats
        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => $responsable->id], $this->enTetes($this->admin))->assertOk()
            ->assertJsonPath('data.responsable.id', $responsable->id)->assertJsonPath('data.responsable.nom', 'Dr Anna Meyer');
        $this->assertContains($responsable->id, collect($this->getJson('/api/v1/stupefiants/responsable', $this->enTetes($jetonAutre))->assertOk()->json('data.candidats'))->pluck('id'));

        // Un autre vétérinaire ne vise pas
        $this->postJson('/api/v1/stupefiants/visa', [], $this->enTetes($jetonAutre))
            ->assertUnprocessable()->assertJsonValidationErrors(['visa' => 'Seul le vétérinaire responsable, Dr Anna Meyer, vise le registre.']);

        // Lui vise toutes les substances tenues, d'un coup
        $this->postJson('/api/v1/stupefiants/visa', ['mention' => 'Registre visé, octobre 2026'], $this->enTetes($jetonResponsable))->assertOk()->assertJsonPath('data.visees', 2);
        $this->assertSame(2, MouvementStupefiant::query()->where('type', 'visa')->count());
        $this->assertSame(['Registre visé, octobre 2026'], MouvementStupefiant::query()->where('type', 'visa')->distinct()->pluck('motif')->all());
    }

    public function test_une_ligne_visa_ne_passe_plus_par_le_chemin_general(): void
    {
        ['employe' => $responsable, 'jeton' => $jeton] = $this->employeAvecJeton($this->clinique);
        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => $responsable->id], $this->enTetes($this->admin))->assertOk();
        $produit = Produit::query()->where('est_stupefiant', true)->firstOrFail();
        $this->postJson('/api/v1/stupefiants/mouvements', ['produit_id' => $produit->id, 'type' => 'visa'], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['type' => 'Le visa se donne par « Viser le registre », réservé au vétérinaire responsable.']);
    }

    public function test_seul_un_administrateur_designe_et_seulement_un_veterinaire_de_la_clinique(): void
    {
        ['employe' => $asv, 'jeton' => $jetonAsv] = $this->employeAvecJeton($this->clinique, ['nom' => 'Julie Muller'], 'asv');
        ['employe' => $veterinaire, 'jeton' => $jetonVeterinaire] = $this->employeAvecJeton($this->clinique);
        $ailleurs = Clinique::factory()->create();
        ['employe' => $etranger] = $this->employeAvecJeton($ailleurs);

        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => $veterinaire->id], $this->enTetes($jetonVeterinaire))->assertForbidden();
        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => $asv->id], $this->enTetes($this->admin))
            ->assertUnprocessable()->assertJsonValidationErrors('employe_id');
        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => $etranger->id], $this->enTetes($this->admin))
            ->assertUnprocessable()->assertJsonValidationErrors('employe_id');
        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => null], $this->enTetes($this->admin))->assertOk()->assertJsonPath('data.responsable', null);
        $this->assertNull($this->clinique->fresh()->responsable_stupefiants_id);
        unset($jetonAsv);
    }

    /**
     * Le responsable doit pouvoir viser au moment du visa (vérification du 01.11.2026) : toujours vétérinaire,
     * accès actif, droit d'inscrire au registre ; sinon personne n'est responsable et personne ne vise
     */
    public function test_un_responsable_qui_ne_peut_plus_viser_ne_vise_plus(): void
    {
        ['employe' => $meyer, 'jeton' => $jetonMeyer] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anne Meyer']);
        $this->recevoir($jetonMeyer);
        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => $meyer->id], $this->enTetes($this->admin))->assertOk();

        // Elle n'est plus vétérinaire : la désignation tombe, elle ne vise plus
        $meyer->update(['role_libelle' => 'Assistante en médecine vétérinaire']);
        $this->assertNull($this->clinique->fresh()->responsable_stupefiants_id);
        $this->getJson('/api/v1/stupefiants/responsable', $this->enTetes($this->admin))->assertOk()->assertJsonPath('data.responsable', null);
        $this->postJson('/api/v1/stupefiants/visa', [], $this->enTetes($jetonMeyer))
            ->assertUnprocessable()->assertJsonValidationErrors(['visa' => 'Aucun vétérinaire responsable n’est désigné pour cette clinique : un administrateur le désigne dans l’onglet Stupéfiants.']);
        // Redevenue vétérinaire, elle ne redevient pas responsable toute seule
        $meyer->update(['role_libelle' => 'Vétérinaire']);
        $this->getJson('/api/v1/stupefiants/responsable', $this->enTetes($this->admin))->assertOk()->assertJsonPath('data.responsable', null);
    }

    public function test_un_responsable_suspendu_ou_sans_le_droit_n_est_ni_responsable_ni_candidat(): void
    {
        ['employe' => $suspendu] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Paul Rochat']);
        ['employe' => $sansDroit] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Léa Favre']);
        ['employe' => $parti] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Marc Dubois']);
        DerogationPermission::query()->create(['employe_id' => $sansDroit->id, 'domaine' => 'stupefiants', 'est_accordee' => false]);
        $parti->update(['fin_contrat_le' => now()->subDays(3)->toDateString()]);

        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => $sansDroit->id], $this->enTetes($this->admin))
            ->assertUnprocessable()->assertJsonValidationErrors('employe_id');
        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => $parti->id], $this->enTetes($this->admin))
            ->assertUnprocessable()->assertJsonValidationErrors('employe_id');

        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => $suspendu->id], $this->enTetes($this->admin))->assertOk();
        $suspendu->update(['acces_suspendu' => true]);
        $etat = $this->getJson('/api/v1/stupefiants/responsable', $this->enTetes($this->admin))->assertOk()->assertJsonPath('data.responsable', null);
        $candidats = collect($etat->json('data.candidats'))->pluck('id');
        $this->assertNotContains($suspendu->id, $candidats);
        $this->assertNotContains($sansDroit->id, $candidats);
        $this->assertNotContains($parti->id, $candidats);
    }

    /** Retiré de la clinique : la désignation tombe, une réaffectation ne la rend pas */
    public function test_un_responsable_retire_de_la_clinique_ne_le_redevient_pas_en_revenant(): void
    {
        ['employe' => $meyer] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anne Meyer']);
        $ailleurs = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => $meyer->id], $this->enTetes($this->admin))->assertOk();

        $this->putJson('/api/v1/equipe/employes/'.$meyer->id.'/cliniques', ['cliniques' => [$ailleurs->id]], $this->enTetes($this->admin))->assertOk();
        $this->assertNull($this->clinique->fresh()->responsable_stupefiants_id);
        $this->putJson('/api/v1/equipe/employes/'.$meyer->id.'/cliniques', ['cliniques' => [$ailleurs->id, $this->clinique->id]], $this->enTetes($this->admin))->assertOk();
        $this->getJson('/api/v1/stupefiants/responsable', $this->enTetes($this->admin))->assertOk()->assertJsonPath('data.responsable', null);
    }

    /** Un autre intitulé de vétérinaire garde la désignation ; sans le droit Stocks, pas candidat */
    public function test_un_titre_toujours_veterinaire_garde_la_designation_et_le_droit_stocks_compte(): void
    {
        ['employe' => $meyer] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anne Meyer']);
        ['employe' => $sansStocks] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dr Paul Rochat']);
        DerogationPermission::query()->create(['employe_id' => $sansStocks->id, 'domaine' => 'stocks', 'est_accordee' => false]);
        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => $meyer->id], $this->enTetes($this->admin))->assertOk();

        $meyer->update(['role_libelle' => 'Vétérinaire chef']);
        $this->assertSame($meyer->id, $this->clinique->fresh()->responsable_stupefiants_id);

        $candidats = collect($this->getJson('/api/v1/stupefiants/responsable', $this->enTetes($this->admin))->assertOk()->json('data.candidats'))->pluck('id');
        $this->assertContains($meyer->id, $candidats);
        $this->assertNotContains($sansStocks->id, $candidats);
        $this->putJson('/api/v1/stupefiants/responsable', ['employe_id' => $sansStocks->id], $this->enTetes($this->admin))
            ->assertUnprocessable()->assertJsonValidationErrors('employe_id');
    }

    /** Retiré d'une clinique seulement : il reste responsable dans l'autre */
    public function test_retire_d_une_clinique_il_reste_responsable_dans_l_autre(): void
    {
        ['employe' => $meyer] = $this->employeAvecJeton($this->clinique, ['nom' => 'Dre Anne Meyer']);
        $autre = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $meyer->cliniques()->attach($autre->id);
        $autre->update(['responsable_stupefiants_id' => $meyer->id]);
        $this->clinique->update(['responsable_stupefiants_id' => $meyer->id]);

        $this->putJson('/api/v1/equipe/employes/'.$meyer->id.'/cliniques', ['cliniques' => [$autre->id]], $this->enTetes($this->admin))->assertOk();
        $this->assertNull($this->clinique->fresh()->responsable_stupefiants_id);
        $this->assertSame($meyer->id, $autre->fresh()->responsable_stupefiants_id);
    }
}
