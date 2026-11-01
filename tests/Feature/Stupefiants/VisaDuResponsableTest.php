<?php

namespace Tests\Feature\Stupefiants;

use App\Models\Clinique;
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
}
