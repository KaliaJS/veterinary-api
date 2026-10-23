<?php

namespace Tests\Unit;

use App\Paiements\ErreurPaiement;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\PasserellePaiementFactice;

/**
 * Le double de Stripe se comporte comme Stripe là où les tests s'y fient
 * (audit du 23.10.2026) : sinon, un test passerait là où la production
 * échoue.
 */
class PasserellePaiementFacticeTest extends TestCase
{
    public function test_une_copie_par_compte_partage_les_donnees_et_note_son_compte(): void
    {
        $plateforme = new PasserellePaiementFactice;
        $societe = $plateforme->pourCompte('acct_a');

        $id = $societe->creerIntentionTerminal(5000, [], 'cle-1', 25)['id'];
        $plateforme->pourCompte('acct_b');

        // La copie garde son compte ; la plateforme reste sans compte et voit tout
        $this->assertSame('acct_a', $societe->compte);
        $this->assertNull($plateforme->compte);
        $this->assertSame('acct_a', $plateforme->intentions[$id]['compte']);
        $this->assertSame([['methode' => 'creerIntentionTerminal', 'compte' => 'acct_a', 'objet' => null, 'cle' => 'cle-1']], $plateforme->appels);
    }

    public function test_une_intention_inconnue_ou_d_un_autre_compte_est_une_erreur_de_paiement(): void
    {
        $stripe = new PasserellePaiementFactice;
        $id = $stripe->pourCompte('acct_a')->creerIntentionEnLigne(3000, [], 'acompte-1')['id'];

        foreach ([[$stripe->pourCompte('acct_a'), 'pi_inconnue'], [$stripe->pourCompte('acct_b'), $id], [$stripe, $id]] as [$passerelle, $intention]) {
            foreach (['lireIntention', 'annulerIntention', 'fraisIntention'] as $methode) {
                try {
                    $passerelle->{$methode}($intention);
                    $this->fail("{$methode} a trouvé {$intention} sur {$passerelle->compte}.");
                } catch (ErreurPaiement $e) {
                    $this->assertSame('resource_missing', $e->codeStripe);
                }
            }
        }
    }

    public function test_une_cle_d_idempotence_rejoue_la_meme_reponse_ou_refuse_d_autres_parametres(): void
    {
        $societe = (new PasserellePaiementFactice)->pourCompte('acct_a');

        $premiere = $societe->creerIntentionTwint(5000, ['facture_id' => '1'], 'https://retour', 'cle-1', 25);
        $this->assertSame($premiere, $societe->creerIntentionTwint(5000, ['facture_id' => '1'], 'https://retour', 'cle-1', 25));
        $this->assertCount(1, $societe->intentions);

        // La même clé sur un autre compte est une autre requête
        $societe->pourCompte('acct_b')->creerIntentionTwint(5000, ['facture_id' => '1'], 'https://retour', 'cle-1', 25);
        $this->assertCount(2, $societe->intentions);

        $this->expectException(ErreurPaiement::class);
        $societe->creerIntentionTwint(6000, ['facture_id' => '1'], 'https://retour', 'cle-1', 25);
    }

    public function test_un_remboursement_ne_rend_jamais_plus_que_le_paiement_et_peut_echouer_sur_demande(): void
    {
        $societe = (new PasserellePaiementFactice)->pourCompte('acct_a');
        $id = $societe->creerIntentionEnLigne(3000, [], 'acompte-1')['id'];
        $societe->conclure($id, 'succeeded');

        $societe->rembourser($id, 'rendu-1', 1000);
        // Même clé, mêmes paramètres : rien de plus n'est rendu
        $societe->rembourser($id, 'rendu-1', 1000);
        $this->assertSame(1000, $societe->montantsRembourses[$id]);

        foreach ([['rendu-2', 2500, 'amount_too_large'], ['rendu-3', null, null], ['rendu-4', null, 'charge_already_refunded']] as [$cle, $centimes, $code]) {
            try {
                $societe->rembourser($id, $cle, $centimes);
                $this->assertNull($code, "{$cle} aurait dû être refusé.");
            } catch (ErreurPaiement $e) {
                $this->assertSame($code, $e->codeStripe);
            }
        }
        $this->assertSame(3000, $societe->montantsRembourses[$id]);
        $this->assertSame([1000, 2000], array_column($societe->rendus, 'centimes'));

        // Une panne programmée : la requête est notée, rien n'est rendu
        $autre = $societe->creerIntentionEnLigne(3000, [], 'acompte-2')['id'];
        $societe->erreurs['rembourser'] = new ErreurPaiement(ErreurPaiement::GENERIQUE, 'balance_insufficient');
        try {
            $societe->rembourser($autre, 'rendu-5');
            $this->fail('La panne programmée n’a pas été levée.');
        } catch (ErreurPaiement $e) {
            $this->assertSame('balance_insufficient', $e->codeStripe);
        }
        $this->assertArrayNotHasKey($autre, $societe->montantsRembourses);
        $this->assertSame('rendu-5', $societe->appels[array_key_last($societe->appels)]['cle']);
    }
}
