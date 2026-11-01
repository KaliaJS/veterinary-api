<?php

namespace Tests\Feature\Facturation;

use App\Enums\MoyenPaiement;
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

    /** SIX 2.3, tableau 8 : NPA et localité obligatoires dans une adresse « S » (vérification du 01.11.2026) */
    public function test_un_client_sans_npa_ou_localite_n_est_pas_debiteur_et_une_clinique_sans_adresse_n_a_pas_de_section_paiement(): void
    {
        $this->clinique->societe->update(['iban' => 'CH9300762011623852957']);
        $facture = $this->facture();
        $facture->client->update(['npa' => null, 'localite' => null, 'rue' => null]);
        $qr = $this->getJson("/api/v1/facturation/factures/{$facture->id}", $this->enTetes($this->jeton))->json('data.qr_facture');
        $this->assertNotNull($qr);
        $this->assertNull($qr['debiteur']);

        $this->clinique->update(['npa' => null]);
        $this->assertNull($this->getJson("/api/v1/facturation/factures/{$facture->id}", $this->enTetes($this->jeton))->json('data.qr_facture'));
    }

    public function test_une_adresse_du_liechtenstein_porte_le_pays_li(): void
    {
        $this->clinique->societe->update(['iban' => 'CH9300762011623852957']);
        $facture = $this->facture();
        $facture->client->update(['npa' => '9490', 'localite' => 'Vaduz']);
        $qr = $this->getJson("/api/v1/facturation/factures/{$facture->id}", $this->enTetes($this->jeton))->json('data.qr_facture');
        $this->assertSame('LI', $qr['debiteur']['pays']);
        $this->assertSame('CH', $qr['creancier']['pays']);
    }

    /**
     * Un paiement reçu par la QR-facture (vérification du 01.11.2026) : la
     * facture se marque payée « par virement », qui ne passe jamais par la
     * caisse (ni arrêté ni clôture) ; une vente du comptoir se paie sur place.
     */
    public function test_une_qr_facture_payee_se_marque_payee_par_virement_hors_de_la_caisse(): void
    {
        $facture = $this->facture();
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", ['moyen_paiement' => 'virement'], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.statut', 'payee')->assertJsonPath('data.moyen_paiement', 'virement');

        // Rien à clôturer : le virement n'est pas passé par la caisse
        $this->postJson('/api/v1/facturation/clotures-caisse', ['fond' => 200, 'especes_comptees' => 200, 'nouveau_fond' => 200], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('factures');
        $this->assertNull($facture->fresh()->cloture_caisse_id);
        $this->getJson('/api/v1/facturation/factures?a_cloturer=1', $this->enTetes($this->jeton))->assertOk()->assertJsonCount(0, 'data');

        $comptoir = $this->facture(['numero' => 'FC-2026-0343', 'vente_comptoir' => true]);
        $this->postJson("/api/v1/facturation/factures/{$comptoir->id}/encaisser", ['moyen_paiement' => 'virement'], $this->enTetes($this->jeton))->assertUnprocessable();
    }

    /** Un ticket du comptoir resté dû se paie sur place : pas de QR-facture qu'on ne pourrait pas encaisser */
    public function test_un_ticket_du_comptoir_n_a_pas_de_section_paiement(): void
    {
        $this->clinique->societe->update(['iban' => 'CH9300762011623852957']);
        $ticket = $this->facture(['vente_comptoir' => true]);
        $this->assertNull($this->getJson("/api/v1/facturation/factures/{$ticket->id}", $this->enTetes($this->jeton))->assertOk()->json('data.qr_facture'));
    }

    /** Le comptoir ne vend jamais « par virement » ; les statistiques du jour disent les virements à part */
    public function test_le_comptoir_refuse_le_virement_et_les_statistiques_le_disent(): void
    {
        $this->postJson('/api/v1/boutique/vente', ['moyen_paiement' => 'virement', 'lignes' => [['produit_id' => 999999, 'quantite' => 1]]], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('moyen_paiement');

        $facture = $this->facture();
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", ['moyen_paiement' => 'virement'], $this->enTetes($this->jeton))->assertOk();
        $this->getJson('/api/v1/facturation/statistiques', $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.du_jour.virement', 338.2);
    }

    /**
     * La colonne `factures.moyen_paiement` est un enum MySQL figé à sa
     * création : chaque moyen doit figurer dans la dernière migration qui la
     * change : une base créée avant la garde, quoi que dise le code. Un
     * moyen ajouté à MoyenPaiement sans migration fait échouer ce test
     * (vérification du 01.11.2026, « virement » refusé par MySQL).
     */
    public function test_chaque_moyen_de_paiement_est_admis_par_la_colonne_mysql(): void
    {
        $derniere = collect(glob(database_path('migrations/*.php')))->sort()
            ->filter(fn (string $fichier): bool => (bool) preg_match("/enum\\('moyen_paiement'/", (string) file_get_contents($fichier)))->last();
        preg_match("/enum\\('moyen_paiement', \\[([^\\]]*)\\]/", (string) file_get_contents($derniere), $m);
        $admis = array_map(fn (string $v): string => trim($v, " '"), explode(',', $m[1] ?? ''));
        foreach (MoyenPaiement::cases() as $moyen) {
            $this->assertContains($moyen->value, $admis, basename($derniere).' n’admet pas « '.$moyen->value.' »');
        }
    }

    /** L'arrêté de caisse ne connaît que les moyens de la caisse : pas de ligne « virement », même à zéro */
    public function test_l_arrete_ne_compte_que_les_moyens_de_la_caisse(): void
    {
        $facture = $this->facture();
        $this->postJson("/api/v1/facturation/factures/{$facture->id}/encaisser", ['moyen_paiement' => 'virement'], $this->enTetes($this->jeton))->assertOk();
        $moyens = $this->getJson('/api/v1/facturation/clotures-caisse/apercu', $this->enTetes($this->jeton))->assertOk()->json('data.moyens');
        $this->assertSame(['tpe', 'twint', 'cash'], array_keys($moyens));
        $this->assertSame(0, array_sum(array_column($moyens, 'nombre')));
    }
}
