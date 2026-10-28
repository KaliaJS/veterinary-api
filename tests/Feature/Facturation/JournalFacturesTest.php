<?php

namespace Tests\Feature\Facturation;

use App\Enums\MoyenPaiement;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Journal d'un mois (23.10.2026) : factures émises et encaissements de
 * la clinique du jeton, mois compté à l'heure de la clinique, totaux de
 * TVA servis au complet (plus de recomposition au front).
 */
class JournalFacturesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    private function facture(string $emiseLe, ?string $payeeLe, float $montant, ?Clinique $clinique = null): Facture
    {
        $facture = ($payeeLe ? Facture::factory()->payee(MoyenPaiement::Especes) : Facture::factory())->create([
            'clinique_id' => ($clinique ?? $this->clinique)->id,
            'emise_le' => CarbonImmutable::parse($emiseLe, 'UTC'),
            'payee_le' => $payeeLe ? CarbonImmutable::parse($payeeLe, 'UTC') : null,
        ]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 1, 'prix_chf' => $montant]);

        return $facture;
    }

    public function test_le_mois_se_compte_a_l_heure_de_la_clinique_et_les_totaux_sont_servis(): void
    {
        // 00:30 le 1er octobre à Zurich : encore septembre en UTC
        $this->facture('2026-09-30 22:30:00', '2026-09-30 22:40:00', 108.10);
        $this->facture('2026-10-15 09:00:00', '2026-10-15 09:10:00', 216.20);
        $this->facture('2026-10-20 10:00:00', null, 54.05);
        // 00:30 le 1er novembre à Zurich : hors du mois
        $this->facture('2026-10-31 23:30:00', '2026-10-31 23:40:00', 999.00);
        $this->facture('2026-10-16 09:00:00', '2026-10-16 09:10:00', 500.00, Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]));

        $reponse = $this->getJson('/api/v1/facturation/factures/journal?mois=2026-10', $this->enTetes($this->jeton))->assertOk();

        $reponse->assertJsonPath('data.taux_tva', 8.1)
            ->assertJsonCount(3, 'data.emises')
            ->assertJsonPath('data.encaissements.nombre', 2)
            ->assertJsonPath('data.encaissements.total_ttc', 324.3)
            ->assertJsonPath('data.encaissements.montant_tva', 24.3)
            ->assertJsonPath('data.encaissements.total_ht', 300);
    }

    /** Les filtres « du » et « au » de la liste suivent le jour de la clinique, « au » compris (24.10.2026). */
    public function test_la_liste_se_filtre_au_jour_de_la_clinique(): void
    {
        // 23:30 le 14 à Zurich (21:30 UTC en été) : le 14
        $tard = $this->facture('2026-07-14 21:30:00', null, 10);
        // 00:30 le 15 à Zurich (encore le 14 en UTC) : le 15
        $this->facture('2026-07-14 22:30:00', null, 20);
        // 23:30 le 13 à Zurich : le 13
        $this->facture('2026-07-13 21:30:00', null, 30);
        // 00:30 le 14 à Zurich (encore le 13 en UTC) : le 14, compté par « du »
        $tot = $this->facture('2026-07-13 22:30:00', null, 40);

        $ids = collect($this->getJson('/api/v1/facturation/factures?du=2026-07-14&au=2026-07-14', $this->enTetes($this->jeton))->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$tard->id, $tot->id], $ids->all());
    }

    public function test_une_facture_sert_son_hors_taxe_son_taux_et_le_total_de_ses_lignes(): void
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 3, 'prix_chf' => 36.03]);

        $this->getJson("/api/v1/facturation/factures/{$facture->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.total_ttc', 108.09)
            ->assertJsonPath('data.montant_tva', 8.1)
            ->assertJsonPath('data.total_ht', 99.99)
            ->assertJsonPath('data.taux_tva', 8.1)
            ->assertJsonPath('data.lignes.0.total_chf', 108.09)
            ->assertJsonPath('data.en_tete.nom', $this->clinique->raison_sociale ?: $this->clinique->nom);
    }

    public function test_la_session_sert_l_en_tete_des_pieces_de_la_clinique(): void
    {
        $this->clinique->update(['raison_sociale' => 'Clinique des Tests', 'rue' => 'Rue du Test 1', 'npa' => '1000', 'ville' => 'Lausanne', 'tel' => '+41 21 000 00 00', 'email' => 'test@exemple.ch']);
        $this->clinique->societe->update(['nom' => 'Tests SA', 'ide' => 'CHE-123.456.789']);

        $this->getJson('/api/v1/auth/moi', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.clinique.en_tete', [
                'nom' => 'Clinique des Tests',
                'adresse' => 'Rue du Test 1, 1000 Lausanne',
                'contact' => '+41 21 000 00 00 · test@exemple.ch',
                'societe' => 'Tests SA',
                'ide' => 'CHE-123.456.789',
                'tva' => true,
                // Le logo du site web (un seul logo, 28.10.2026) : aucun ici
                'logo_url' => null,
            ]);
    }

    public function test_le_mois_est_exige_au_bon_format(): void
    {
        $this->getJson('/api/v1/facturation/factures/journal?mois=10.2026', $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mois');
    }

    public function test_sans_le_droit_facturation_le_journal_est_refuse(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton($this->clinique, [], 'assistant');

        $this->getJson('/api/v1/facturation/factures/journal?mois=2026-10', $this->enTetes($jeton))->assertForbidden();
    }
}
