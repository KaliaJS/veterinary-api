<?php

namespace Tests\Feature\Ordonnances;

use App\Enums\StatutOrdonnance;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demande de Jim (01.11.2026) : un code QR sur l'ordonnance mène à une page
 * de veterinary.app qui prouve son authenticité ; sous le code QR, une
 * adresse courte et un code à chiffres pour vérifier sans téléphone.
 */
class VerificationOrdonnanceTest extends TestCase
{
    use RefreshDatabase;

    private function ordonnance(array $attributs = []): Ordonnance
    {
        $clinique = Clinique::factory()->create(['nom' => 'Clinique des Deux Rives', 'raison_sociale' => null]);
        $client = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'nom' => 'Meunier', 'rue' => 'Rue des Pâquis 8']);
        $patient = Patient::factory()->for($client)->create(['nom' => 'Pilou', 'espece' => 'CH']);
        $ordonnance = Ordonnance::factory()->create([
            'organisation_id' => $clinique->societe->organisation_id,
            'clinique_id' => $clinique->id,
            'patient_id' => $patient->id,
            'numero' => 'OR-2026-041',
            ...$attributs,
        ]);
        OrdonnanceLigne::factory()->create(['ordonnance_id' => $ordonnance->id, 'quantite' => 2, 'posologie' => '1 comprimé matin et soir']);
        // Un code connu : les essais faux des tests ne tombent jamais dessus par hasard
        $ordonnance->forceFill(['code_verification' => '4821'])->save();

        return $ordonnance;
    }

    public function test_chaque_ordonnance_a_un_code_de_quatre_chiffres_unique_avec_son_numero(): void
    {
        $une = $this->ordonnance();
        $this->assertMatchesRegularExpression('/^\d{4}$/', $une->code_verification);
        // Une autre organisation peut avoir le même numéro : jamais avec le même code
        $autres = collect(range(1, 30))->map(fn () => Ordonnance::factory()->create(['numero' => 'OR-2026-041'])->code_verification);
        $this->assertNotContains($une->code_verification, $autres);
        $this->assertCount(30, $autres->unique());
        config(['app.frontend_url' => 'https://veterinary.app/']);
        $this->assertSame('veterinary.app/ordo', Ordonnance::adresseVerification());
    }

    public function test_le_numero_et_le_code_prouvent_l_ordonnance_sans_dire_le_detenteur(): void
    {
        $ordonnance = $this->ordonnance();
        $code = ' '.$ordonnance->code_verification.' ';

        $reponse = $this->getJson('/api/v1/public/ordonnances/verifier?'.http_build_query(['numero' => 'or-2026-041', 'code' => $code]))
            ->assertOk()
            ->assertJsonPath('data.numero', 'OR-2026-041')
            ->assertJsonPath('data.est_valable', true)
            ->assertJsonPath('data.clinique.nom', 'Clinique des Deux Rives')
            ->assertJsonPath('data.animal', ['nom' => 'Pilou', 'espece' => 'Chien'])
            ->assertJsonPath('data.lignes.0.quantite', 2)
            ->assertJsonPath('data.lignes.0.posologie', '1 comprimé matin et soir');

        $this->assertStringNotContainsString('Meunier', $reponse->getContent());
        $this->assertStringNotContainsString('Pâquis', $reponse->getContent());
    }

    public function test_un_code_seul_ou_faux_ne_montre_rien(): void
    {
        $ordonnance = $this->ordonnance();

        $this->getJson('/api/v1/public/ordonnances/verifier?code='.$ordonnance->code_verification)->assertNotFound();
        $this->getJson('/api/v1/public/ordonnances/verifier?numero=OR-2026-042&code='.$ordonnance->code_verification)->assertNotFound();
        $faux = str_pad((string) (((int) $ordonnance->code_verification + 1) % 10_000), 4, '0', STR_PAD_LEFT);
        $this->getJson('/api/v1/public/ordonnances/verifier?numero=OR-2026-041&code='.$faux)
            ->assertNotFound()
            ->assertJsonPath('message', 'Aucune ordonnance ne correspond à ce numéro et à ce code.');
    }

    public function test_une_ordonnance_cloturee_ou_renouvelee_le_dit(): void
    {
        $ordonnance = $this->ordonnance(['statut' => StatutOrdonnance::Cloturee]);
        Ordonnance::factory()->create(['organisation_id' => $ordonnance->organisation_id, 'clinique_id' => $ordonnance->clinique_id, 'patient_id' => $ordonnance->patient_id, 'numero' => 'OR-2026-052', 'ordonnance_precedente_id' => $ordonnance->id, 'signee_le' => '2026-11-20']);

        $this->getJson('/api/v1/public/ordonnances/verifier?numero=OR-2026-041&code='.$ordonnance->code_verification)
            ->assertOk()
            ->assertJsonPath('data.est_valable', false)
            ->assertJsonPath('data.statut', 'stop')
            ->assertJsonPath('data.renouvelee_par.numero', 'OR-2026-052');
    }

    /** Changer d'adresse ne permet pas d'essayer tous les codes d'une ordonnance : dix essais faux par numéro et par heure */
    public function test_les_essais_faux_sont_limites_par_numero(): void
    {
        $ordonnance = $this->ordonnance();
        foreach (range(1, 10) as $essai) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$essai])
                ->getJson('/api/v1/public/ordonnances/verifier?numero=OR-2026-041&code=000'.($essai % 10))
                ->assertNotFound();
        }
        // Même le bon code, depuis une autre adresse, attend une heure
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->getJson('/api/v1/public/ordonnances/verifier?numero=OR-2026-041&code='.$ordonnance->code_verification)
            ->assertTooManyRequests();
        // Une autre ordonnance n'est pas touchée
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.2'])
            ->getJson('/api/v1/public/ordonnances/verifier?numero=OR-2026-099&code=1234')
            ->assertNotFound();
    }

    /** Le code QR : un jeton long et secret, qui ne se devine pas (vérification du 01.11.2026) */
    public function test_le_code_qr_verifie_par_un_jeton_long(): void
    {
        $ordonnance = $this->ordonnance();
        $this->assertSame(32, strlen($ordonnance->jeton_verification));

        $this->getJson('/api/v1/public/ordonnances/verifier?t='.$ordonnance->jeton_verification)
            ->assertOk()->assertJsonPath('data.numero', 'OR-2026-041')->assertJsonPath('data.est_valable', true);
        $this->getJson('/api/v1/public/ordonnances/verifier?t='.str_repeat('a', 32))
            ->assertNotFound()->assertJsonPath('message', 'Ce code QR ne correspond à aucune ordonnance.');
    }

    /** La réponse publique ne dit que ce qui se compare au papier : rien du détenteur, aucun identifiant interne */
    public function test_la_reponse_publique_ne_dit_que_ce_qui_se_compare(): void
    {
        $ordonnance = $this->ordonnance();
        $data = $this->getJson('/api/v1/public/ordonnances/verifier?t='.$ordonnance->jeton_verification)->assertOk()->json('data');

        $this->assertSame(['numero', 'signee_le', 'statut', 'renouvelee_par', 'est_valable', 'clinique', 'prescripteur', 'animal', 'renouvellements', 'lignes'], array_keys($data));
        $this->assertSame(['nom', 'espece'], array_keys($data['animal']));
        $this->assertSame(['produit', 'quantite', 'posologie', 'duree'], array_keys($data['lignes'][0]));
    }

    /** Des caractères invisibles ou de contrôle dans le numéro ne donnent pas un compteur neuf (vérification du 01.11.2026) */
    public function test_le_numero_est_nettoye_avant_le_compteur(): void
    {
        $this->ordonnance();
        foreach (range(1, 10) as $essai) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.'.$essai])
                ->getJson('/api/v1/public/ordonnances/verifier?'.http_build_query(['numero' => 'or-2026-041'.str_repeat("\u{200B}", $essai), 'code' => '0000']))
                ->assertNotFound();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.3.1'])
            ->getJson('/api/v1/public/ordonnances/verifier?numero=OR-2026-041&code=4821')
            ->assertTooManyRequests();
    }

    /** Parcourir les numéros depuis une adresse : dix essais faux par heure, quel que soit le numéro */
    public function test_les_essais_faux_sont_limites_par_adresse(): void
    {
        $this->ordonnance();
        foreach (range(1, 10) as $n) {
            $this->getJson('/api/v1/public/ordonnances/verifier?numero=OR-2026-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT).'&code=0000')->assertNotFound();
        }
        $this->getJson('/api/v1/public/ordonnances/verifier?numero=OR-2026-041&code=4821')
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Trop d’essais faux : réessayez dans une heure, scannez le code QR, ou appelez la clinique.');
    }

    public function test_les_essais_sont_limites(): void
    {
        $this->ordonnance();
        foreach (range(1, 20) as $essai) {
            $this->getJson('/api/v1/public/ordonnances/verifier?numero=OR-2026-041&code=0000');
        }
        $this->getJson('/api/v1/public/ordonnances/verifier?numero=OR-2026-041&code=0000')->assertTooManyRequests();
    }
}
