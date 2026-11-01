<?php

namespace Tests\Feature\Facturation;

use App\Models\Client;
use App\Models\Clinique;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Patient;
use App\Support\QrFacture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La QR-facture suisse (01.11.2026, choix de Jim : « la QR facture suisse
 * aux normes SIX 2.3 comme pour le foodtruck », pas Stripe Invoicing) :
 * l'IBAN de la société, validé ; la référence calculée d'après la norme
 * (QRR avec un QR-IBAN, SCOR ISO 11649 sinon) ; la section paiement servie
 * avec la facture tant qu'il reste à payer.
 */
class QrFactureTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives', 'rue' => 'Rue du Simplon 24', 'npa' => '1006', 'ville' => 'Lausanne']);
        $this->clinique->societe->update(['nom' => 'Deux Rives Vétérinaires SA']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    private function facture(array $attributs = []): Facture
    {
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'prenom' => 'Paul', 'nom' => 'Meunier', 'rue' => 'Rue des Pâquis 8', 'npa' => '1201', 'localite' => 'Genève']);
        $patient = Patient::factory()->for($client)->create(['organisation_id' => $this->clinique->societe->organisation_id]);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'client_id' => $client->id, 'patient_id' => $patient->id, 'numero' => 'FC-2026-0341', ...$attributs]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 1, 'prix_chf' => 338.20]);

        return $facture;
    }

    /** Les valeurs d'exemple officielles : IBAN, QR-IBAN, référence QR de SIX, référence ISO 11649 */
    public function test_les_calculs_suivent_les_normes(): void
    {
        $this->assertTrue(QrFacture::ibanValide('CH93 0076 2011 6238 5295 7'));
        $this->assertFalse(QrFacture::ibanValide('CH93 0076 2011 6238 5295 8'));
        $this->assertTrue(QrFacture::estQrIban('CH44 3199 9123 0008 8901 2'));
        $this->assertFalse(QrFacture::estQrIban('CH93 0076 2011 6238 5295 7'));
        // SIX, Swiss Implementation Guidelines QR-bill : « 21 00000 00003 13947 14300 09017 »
        $this->assertSame('210000000003139471430009017', QrFacture::referenceQr('21000000000313947143000901'));
        // ISO 11649 : « RF18 5390 0754 7034 »
        $this->assertSame('RF18539007547034', QrFacture::referenceCreancier('539007547034'));
    }

    public function test_l_iban_de_la_societe_se_valide_et_se_garde_sans_espaces(): void
    {
        $url = "/api/v1/organisation/societes/{$this->clinique->societe_id}";
        $this->patchJson($url, ['iban' => 'DE89 3704 0044 0532 0130 00'], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('iban');
        $this->patchJson($url, ['iban' => 'CH93 0076 2011 6238 5295 8'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['iban' => 'Cet IBAN a une erreur : vérifiez chaque caractère.']);
        $this->patchJson($url, ['iban' => 'ch93 0076 2011 6238 5295 7'], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.iban', 'CH9300762011623852957');
        $this->patchJson($url, ['iban' => null], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.iban', null);
    }

    public function test_une_facture_a_payer_porte_sa_section_paiement_avec_reference_scor(): void
    {
        $this->clinique->societe->update(['iban' => 'CH9300762011623852957']);
        $facture = $this->facture();

        $qr = $this->getJson("/api/v1/facturation/factures/{$facture->id}", $this->enTetes($this->jeton))->assertOk()->json('data.qr_facture');

        $this->assertSame('CH9300762011623852957', $qr['iban']);
        $this->assertSame(['nom' => 'Deux Rives Vétérinaires SA', 'rue' => 'Rue du Simplon 24', 'npa' => '1006', 'localite' => 'Lausanne', 'pays' => 'CH'], $qr['creancier']);
        $this->assertSame(['nom' => 'Paul Meunier', 'rue' => 'Rue des Pâquis 8', 'npa' => '1201', 'localite' => 'Genève', 'pays' => 'CH'], $qr['debiteur']);
        $this->assertEquals(338.2, $qr['montant']);
        $this->assertSame('SCOR', $qr['type_reference']);
        $this->assertSame(QrFacture::referenceCreancier('FC20260341'), $qr['reference']);
        $this->assertMatchesRegularExpression('/^RF\d{2}FC20260341$/', $qr['reference']);
        $this->assertSame('Facture FC-2026-0341', $qr['message']);
    }

    public function test_avec_un_qr_iban_la_reference_est_une_reference_qr(): void
    {
        $this->clinique->societe->update(['iban' => 'CH4431999123000889012']);
        $qr = $this->getJson("/api/v1/facturation/factures/{$this->facture()->id}", $this->enTetes($this->jeton))->json('data.qr_facture');
        $this->assertSame('QRR', $qr['type_reference']);
        $this->assertMatchesRegularExpression('/^\d{27}$/', $qr['reference']);
        $this->assertSame($qr['reference'], QrFacture::referenceQr(substr($qr['reference'], 0, 26)));
    }

    public function test_pas_de_section_paiement_payee_annulee_ou_sans_iban(): void
    {
        $facture = $this->facture();
        $this->assertNull($this->getJson("/api/v1/facturation/factures/{$facture->id}", $this->enTetes($this->jeton))->json('data.qr_facture'));

        $this->clinique->societe->update(['iban' => 'CH9300762011623852957']);
        $payee = $this->facture(['numero' => 'FC-2026-0342', 'statut' => 'payee', 'payee_le' => now(), 'moyen_paiement' => 'cash']);
        $this->assertNull($this->getJson("/api/v1/facturation/factures/{$payee->id}", $this->enTetes($this->jeton))->json('data.qr_facture'));
    }

    public function test_le_proprietaire_recoit_la_section_paiement_dans_son_espace(): void
    {
        $this->clinique->societe->update(['iban' => 'CH9300762011623852957']);
        $facture = $this->facture();
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $facture->client->update(['user_id' => $user->id]);

        $document = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jeton))->assertOk()->json('data'))->firstWhere('type', 'FACTURE');
        $this->assertSame('CH9300762011623852957', $document['pdf']['qr_facture']['iban']);
        $this->assertEquals(338.2, $document['pdf']['qr_facture']['montant']);
    }
}
