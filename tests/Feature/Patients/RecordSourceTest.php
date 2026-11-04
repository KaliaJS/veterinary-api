<?php

namespace Tests\Feature\Patients;

use App\Auth\ContexteClinique;
use App\Enums\Espece;
use App\Models\Clinique;
use App\Models\Document;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\ReleveDentaire;
use App\Models\ReleveOphtalmologique;
use App\Models\Vaccination;
use App\Models\Visite;
use App\Support\BackfillRecordSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Chaque pesée, document, visite, vaccin et relevé garde l'organisation qui
 * l'a fait (étape 0 de la fusion des dossiers, 02.11.2026) : une fois les
 * dossiers fusionnés, chaque acte dira de quelle clinique il vient. Fait par
 * le propriétaire : aucune organisation.
 */
class RecordSourceTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const MODELES = [Pesee::class, Document::class, Visite::class, Vaccination::class, ReleveOphtalmologique::class, ReleveDentaire::class];

    public function test_un_acte_de_la_clinique_garde_son_organisation(): void
    {
        $clinique = Clinique::factory()->create();
        $organisation = $clinique->societe->organisation_id;
        $patient = Patient::factory()->create(['organisation_id' => $organisation]);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);

        $id = $this->postJson("/api/v1/patients/{$patient->id}/pesees", ['poids_kg' => 12.4], $this->enTetes($jeton))->assertCreated()->json('data.id');
        $this->assertSame($organisation, Pesee::query()->find($id)->source_organisation_id);

        // Tous les actes, créés dans le contexte de la clinique
        app(ContexteClinique::class)->definir($clinique);
        foreach (self::MODELES as $modele) {
            $acte = $modele::factory()->create(['patient_id' => $patient->id]);
            $this->assertSame($organisation, $acte->refresh()->source_organisation_id, $modele);
        }
    }

    public function test_un_acte_du_proprietaire_n_a_pas_d_organisation(): void
    {
        // Même ouvert depuis un compte d'équipe (jeton d'une clinique) : c'est son animal à lui
        ['jeton' => $jeton] = $this->employeAvecJeton(Clinique::factory()->create());

        $id = $this->postJson('/api/v1/espace/animaux', ['nom' => 'Félix', 'espece' => 'CT', 'sexe' => 'Mâle', 'poids_kg' => 4.2], $this->enTetes($jeton))->assertCreated()->json('data.id');

        $pesee = Pesee::query()->where('patient_id', $id)->sole();
        $this->assertNull($pesee->source_organisation_id);
    }

    public function test_les_actes_d_avant_recoivent_l_organisation_du_dossier(): void
    {
        $clinique = Clinique::factory()->create();
        $organisation = $clinique->societe->organisation_id;
        $patient = Patient::factory()->create(['organisation_id' => $organisation]);
        $animalDuProprietaire = Patient::factory()->create(['organisation_id' => null]);
        app(ContexteClinique::class)->oublier();
        $actes = array_map(fn (string $modele) => $modele::factory()->create(['patient_id' => $patient->id]), self::MODELES);
        $peseeDuProprietaire = Pesee::factory()->create(['patient_id' => $animalDuProprietaire->id]);
        foreach ($actes as $acte) {
            DB::table($acte->getTable())->update(['source_organisation_id' => null]);
        }

        app(BackfillRecordSources::class)->run();

        foreach ($actes as $acte) {
            $this->assertSame($organisation, $acte->refresh()->source_organisation_id, $acte::class);
        }
        $this->assertNull($peseeDuProprietaire->refresh()->source_organisation_id);
    }

    public function test_une_dent_inchangee_garde_la_clinique_qui_l_a_relevee(): void
    {
        $a = Clinique::factory()->create();
        $patient = Patient::factory()->create(['organisation_id' => $a->societe->organisation_id, 'espece' => Espece::Chien]);
        $autre = Clinique::factory()->create()->societe->organisation_id;
        ReleveDentaire::factory()->create(['patient_id' => $patient->id, 'dent' => 104, 'etats' => ['tartre'], 'source_organisation_id' => $autre]);
        ['jeton' => $jeton] = $this->employeAvecJeton($a);

        $this->putJson("/api/v1/patients/{$patient->id}/dents", ['teeth' => ['104' => ['states' => ['tartre']], '204' => ['states' => ['fracture']]], 'overview' => ['gingivitis' => null, 'calculus' => null, 'occlusion' => null, 'local_block' => null]], $this->enTetes($jeton))->assertOk();

        $this->assertSame($autre, ReleveDentaire::query()->where('patient_id', $patient->id)->where('dent', 104)->value('source_organisation_id'));
        $this->assertSame($a->societe->organisation_id, ReleveDentaire::query()->where('patient_id', $patient->id)->where('dent', 204)->value('source_organisation_id'));
    }
}
