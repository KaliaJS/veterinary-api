<?php

namespace Tests\Feature\Isabv;

use App\Enums\DeclarationStatus;
use App\Enums\IsabvForm;
use App\Jobs\SendAntibioticDeclaration;
use App\Models\AntibioticDeclaration;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\IsabvConnection;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\Produit;
use App\Models\Sejour;
use App\Models\SejourSoin;
use App\Models\Stock;
use App\Services\Isabv\Declarations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\PrepareLeSiAbv;
use Tests\TestCase;

/**
 * SI ABV, suites de la deuxième vérification (08.11.2026) : annulée pendant
 * l'envoi, elle se désactive ; une quantité totale saisie fait foi ; l'unité
 * d'élevage reprend la fiche du détenteur ; un renouvellement ne compte
 * qu'une déclaration par chaîne de corrections ; un token refusé ne prend
 * rien ; une correction sans changement ne remplace rien ; les jours d'un
 * traitement arrêté par la sortie, sans prises annulées ; un antibiotique de
 * la liste ne se vend pas librement.
 */
class DeuxiemePasseTest extends TestCase
{
    use EmetDesJetonsApi;
    use PrepareLeSiAbv;
    use RefreshDatabase;

    private const URL = 'https://isabv.test/api/isabv';

    private Clinique $clinique;

    private Patient $patient;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.isabv.url' => self::URL]);
        $this->donneesDeBaseSiAbv();
        $this->clinique = Clinique::factory()->create(['nombre_boxes' => 8]);
        $this->cliniqueQuiDeclare($this->clinique);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $this->patient = Patient::factory()->for($client)->create(['espece' => 'CH']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function declaration(array $attributs = [], ?Patient $patient = null): AntibioticDeclaration
    {
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => ($patient ?? $this->patient)->id]);
        $declaration = AntibioticDeclaration::withoutGlobalScopes()->create([
            'clinique_id' => $this->clinique->id, 'recipe_number' => (string) Str::uuid(), 'form' => IsabvForm::CompanionTreatment, 'status' => DeclarationStatus::Sent, 'sent_at' => now(),
            'source_type' => $ordonnance->getMorphClass(), 'source_id' => $ordonnance->id, 'patient_id' => ($patient ?? $this->patient)->id,
            'visit_on' => aujourdhui(), 'species_code' => '1.5', 'weight_kg' => 30, 'diagnosis_key' => '3', ...$attributs,
        ]);
        $declaration->lines()->create(['preparation_code' => '12345-01', 'route_code' => 'po', 'dose_1' => 1, 'frequency_1' => '12', 'days_1' => 7, 'total_quantity' => 14, 'given_quantity' => 14]);

        return $declaration;
    }

    private function corriger(AntibioticDeclaration $declaration, array $body)
    {
        return $this->putJson('/api/v1/isabv/declarations/'.$declaration->id, $body, $this->enTetes($this->jeton));
    }

    public function test_annulee_pendant_l_envoi_elle_se_desactive(): void
    {
        Queue::fake();
        $declaration = $this->declaration(['status' => DeclarationStatus::Ready, 'sent_at' => null]);
        Http::fake([
            self::URL.'/jwt/create' => Http::response('a.b.c'),
            // Pendant que l'OSAV répond, l'ordonnance est annulée
            self::URL.'/psw/prescription/EH' => function () use ($declaration) {
                app(Declarations::class)->cancelFor(Ordonnance::withoutGlobalScopes()->find($declaration->source_id));

                return Http::response(['protocol' => ['uuid' => 'p-1', 'status' => 'OK']], 201);
            },
            self::URL.'/psw/prescription/deactivate/*' => Http::response(['status' => 'OK']),
        ]);

        (new SendAntibioticDeclaration($declaration->id))->handle();

        // Reçue par l'OSAV pendant l'annulation : la même tâche la désactive aussitôt
        $this->assertSame(DeclarationStatus::Deactivated, $declaration->fresh()->status);
        $this->assertFalse($declaration->fresh()->is_outcome_unknown);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/deactivate/'.$declaration->recipe_number));
    }

    public function test_une_quantite_totale_saisie_fait_foi_et_se_recalcule_une_fois_videe(): void
    {
        Queue::fake();
        $declaration = $this->declaration(['status' => DeclarationStatus::ToComplete, 'sent_at' => null, 'weight_kg' => null]);
        $line = $declaration->lines()->value('id');

        $this->corriger($declaration, ['lines' => [['id' => $line, 'isabv' => ['total_quantity' => 20]]]])->assertOk()->assertJsonPath('data.lines.0.total_quantity', 20)->assertJsonPath('data.lines.0.is_total_entered', true);
        // Un autre champ enregistré : la saisie reste
        $this->corriger($declaration, ['isabv' => ['weight_kg' => 29], 'lines' => [['id' => $line, 'isabv' => ['days_1' => 7]]]])->assertOk()->assertJsonPath('data.lines.0.total_quantity', 20);
        // Vidée : la formule de l'OSAV revient
        $this->corriger($declaration, ['lines' => [['id' => $line, 'isabv' => ['total_quantity' => null]]]])->assertOk()->assertJsonPath('data.lines.0.total_quantity', 14)->assertJsonPath('data.lines.0.is_total_entered', false);
    }

    public function test_l_unite_d_elevage_reprend_la_fiche_du_detenteur_completee_entre_temps(): void
    {
        Queue::fake();
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'rue' => null, 'npa' => null, 'localite' => null]);
        $vache = Patient::factory()->for($client)->create(['espece' => 'BV', 'race' => null, 'isabv_species' => '2.1.5']);
        $declaration = $this->declaration(['status' => DeclarationStatus::ToComplete, 'sent_at' => null, 'form' => IsabvForm::LivestockTreatment, 'species_code' => '2.1.5', 'diagnosis_key' => '2.1', 'weight_kg' => null], $vache);
        $declaration->lines()->update(['frequency_1' => '7']);

        $this->corriger($declaration, ['isabv' => ['holding_number' => null, 'holding_address' => null]])->assertOk()
            ->assertJsonPath('data.status', 'to_complete')->assertJsonFragment(['l’adresse de l’exploitation']);
        $client->update(['holding_number' => '1234567', 'holding_address' => 'Alpage du Moléson, 1663 Gruyères']);

        $this->corriger($declaration, ['isabv' => ['holding_number' => null, 'holding_address' => null]])->assertOk()
            ->assertJsonPath('data.holding_address', 'Alpage du Moléson, 1663 Gruyères')->assertJsonPath('data.holding_number', '1234567')->assertJsonPath('data.status', 'ready');
    }

    public function test_un_renouvellement_pendant_une_correction_ne_declare_qu_une_fois(): void
    {
        Queue::fake();
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'isabv_preparation_code' => '12345-01']);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 20]);
        $ordonnance = $this->postJson('/api/v1/ordonnances', ['patient_id' => $this->patient->id, 'renouvellements' => 1, 'isabv' => ['diagnosis_key' => '3', 'weight_kg' => 30],
            'lignes' => [['produit_id' => $produit->id, 'posologie' => '1 cp', 'isabv' => ['dose_1' => 1, 'frequency_1' => '12', 'days_1' => 7]]]], $this->enTetes($this->jeton))->assertCreated()->json('data');
        $sent = AntibioticDeclaration::withoutGlobalScopes()->sole();
        $sent->update(['status' => DeclarationStatus::Sent]);
        $this->corriger($sent, ['lines' => [['id' => $sent->lines()->value('id'), 'isabv' => ['days_1' => null]]]])->assertOk()->assertJsonPath('data.status', 'to_complete');

        $renewal = $this->postJson('/api/v1/ordonnances/'.$ordonnance['id'].'/renouveler', [], $this->enTetes($this->jeton))->assertCreated()->json('data');

        $this->assertSame(1, AntibioticDeclaration::withoutGlobalScopes()->where('source_id', $renewal['id'])->count());
    }

    public function test_un_token_refuse_ne_prend_pas_la_declaration(): void
    {
        $declaration = $this->declaration(['status' => DeclarationStatus::Ready, 'sent_at' => null]);
        Http::fake([self::URL.'/jwt/create' => Http::response('', 401)]);

        (new SendAntibioticDeclaration($declaration->id))->handle();

        $this->assertFalse($declaration->fresh()->is_outcome_unknown);
        $this->corriger($declaration, ['isabv' => ['weight_kg' => 31]])->assertOk();
    }

    public function test_une_correction_sans_changement_ne_remplace_rien_et_la_reactivation_efface_le_doublon(): void
    {
        Queue::fake();
        $declaration = $this->declaration(['osav_message' => 'L’OSAV la tient pour un doublon et l’a écartée.']);

        $this->corriger($declaration, ['isabv' => ['weight_kg' => 30]])->assertOk()->assertJsonPath('data.id', $declaration->id)->assertJsonPath('data.status', 'sent');
        $this->assertSame(1, AntibioticDeclaration::withoutGlobalScopes()->count());

        Http::fake([self::URL.'/jwt/create' => Http::response('a.b.c'), self::URL.'/psw/prescription/getDuplicatePrescriptions' => Http::response(['prescriptionIDs' => [$declaration->recipe_number]]),
            self::URL.'/psw/prescription/reactivateDuplicatePrescriptions' => Http::response(['status' => 'OK'])]);
        $this->postJson('/api/v1/isabv/duplicates/reactivate', ['ids' => [$declaration->id]], $this->enTetes($this->jeton))->assertOk();
        $this->assertNull($declaration->fresh()->osav_message);
    }

    public function test_la_sortie_donne_ses_jours_au_traitement_sans_les_prises_annulees(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', 'Europe/Zurich'));
        $sejour = Sejour::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'client_id' => $this->patient->client_id,
            'box' => 2, 'statut' => 'surv', 'perfusion' => null, 'admis_le' => now()->subDay()]);
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'isabv_preparation_code' => '12345-01', 'dose_unit' => 'cp', 'content_per_unit' => 20]);
        Stock::factory()->create(['produit_id' => $produit->id, 'clinique_id' => $this->clinique->id, 'quantite' => 20]);
        $traitement = $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/traitements", ['label' => 'Amoxicilline', 'product_id' => $produit->id, 'dose_amount' => 1, 'times' => [480]],
            $this->enTetes($this->jeton))->assertCreated()->json('data');
        foreach (['2026-11-03' => null, '2026-11-04' => null, '2026-11-05' => now()] as $jour => $annulee) {
            SejourSoin::create(['sejour_id' => $sejour->id, 'treatment_id' => $traitement['id'], 'jour' => $jour, 'prevu_a' => 480, 'acte' => 'Amoxicilline', 'fait' => true, 'fait_le' => now(), 'cancelled_at' => $annulee]);
        }

        // Jamais arrêté : la sortie du séjour donne ses jours
        $this->postJson("/api/v1/hospitalisation/sejours/{$sejour->id}/sortie", [], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame(2, AntibioticDeclaration::withoutGlobalScopes()->sole()->lines()->value('days_1'));
    }

    public function test_un_antibiotique_de_la_liste_ne_se_vend_pas_librement(): void
    {
        $produit = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'isabv_preparation_code' => '12345-01', 'est_sur_ordonnance' => false, 'est_stupefiant' => false]);
        $topique = Produit::factory()->medicament()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'isabv_preparation_code' => '33333-01', 'est_sur_ordonnance' => false, 'est_stupefiant' => false]);

        $this->assertStringContainsString('antibiotique', (string) $produit->refusVenteLibre());
        $this->assertNull($topique->refusVenteLibre());
        // La clinique qui ne déclare pas l'a pourtant aussi : la règle tient à la liste, pas au réglage
        IsabvConnection::withoutGlobalScopes()->update(['variant' => IsabvConnection::VIA_OSAV_WEB]);
        $this->assertNotNull($produit->fresh()->refusVenteLibre());
    }
}
