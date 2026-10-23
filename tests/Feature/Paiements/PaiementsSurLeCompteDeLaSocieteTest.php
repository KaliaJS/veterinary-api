<?php

namespace Tests\Feature\Paiements;

use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\EvenementStripe;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\Societe;
use App\Models\TerminalPaiement;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;
use App\Services\Paiements\AcomptesRendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DeposeSansCompte;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Chaque appel à Stripe pour une clinique vise le compte de paiement de
 * sa société (Connect, 23.10.2026) : encaissement en caisse, acomptes,
 * remboursements. La plateforme n'encaisse ni ne rend rien elle-même :
 * elle ne paie jamais de frais à cause des cliniques. Les clés
 * d'idempotence protègent des doubles créations, et une erreur de Stripe
 * (intention introuvable, remboursement refusé) reste lisible.
 */
class PaiementsSurLeCompteDeLaSocieteTest extends TestCase
{
    use DeposeSansCompte;
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const COMPTE = 'acct_deux_rives';

    /** Ce qui crée, déplace ou rend de l'argent chez Stripe */
    private const MOUVEMENTS = ['creerIntentionTerminal', 'creerIntentionTwint', 'creerIntentionEnLigne', 'rembourser'];

    private PasserellePaiementFactice $stripe;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new PasserellePaiementFactice;
        $this->app->instance(PasserellePaiement::class, $this->stripe);
        $societe = Societe::factory()->create(['stripe_compte_id' => self::COMPTE]);
        $this->clinique = Clinique::factory()->create(['societe_id' => $societe->id, 'fuseau_horaire' => 'Europe/Zurich']);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    public function test_tout_le_parcours_en_caisse_vise_le_compte_de_la_societe(): void
    {
        $facture = $this->factureDue(120);

        // Terminal : envoyé au lecteur, relu, annulé par la caisse
        $premier = $this->payer($facture, 'tpe')->assertCreated()->json('data.id');
        $this->getJson("/api/v1/facturation/paiements/{$premier}", $this->enTetes($this->jeton))->assertOk();
        $this->postJson("/api/v1/facturation/paiements/{$premier}/annuler", [], $this->enTetes($this->jeton))->assertJsonPath('data.statut', 'annule');

        // Puis TWINT, payé : la facture est encaissée
        $second = Paiement::query()->findOrFail($this->payer($facture, 'twint')->assertCreated()->json('data.id'));
        $this->stripe->conclure($second->stripe_payment_intent_id, 'succeeded');
        $this->getJson("/api/v1/facturation/paiements/{$second->id}", $this->enTetes($this->jeton))->assertJsonPath('data.facture.statut', 'payee');

        $this->assertSame(
            ['creerIntentionTerminal', 'presenterSurLecteur', 'lireIntention', 'annulerSurLecteur', 'annulerIntention', 'creerIntentionTwint', 'lireIntention'],
            array_column($this->stripe->appels, 'methode'),
        );
        $this->assertSame([self::COMPTE], array_values(array_unique(array_column($this->stripe->appels, 'compte'))));
        $this->assertAucunMouvementSurLaPlateforme();

        // Une clé par tentative, jamais réutilisée d'une tentative à l'autre
        $cles = array_column([...$this->stripe->appelsDe('creerIntentionTerminal'), ...$this->stripe->appelsDe('creerIntentionTwint')], 'cle');
        $this->assertCount(2, array_unique($cles));
        foreach ($cles as $cle) {
            $this->assertStringStartsWith("facture-{$facture->id}-", $cle);
        }
        // 0,5 % de 120 CHF sur chaque intention, prise par la plateforme sur le compte de la société
        $this->assertSame([60, 60], array_column($this->stripe->intentions, 'commission'));
        $this->assertSame([self::COMPTE, self::COMPTE], array_column($this->stripe->intentions, 'compte'));
    }

    public function test_un_paiement_recu_a_tort_est_rendu_une_seule_fois_en_entier_par_le_compte_qui_l_a_recu(): void
    {
        $facture = $this->factureDue(120);
        $paiement = Paiement::query()->findOrFail($this->payer($facture, 'tpe')->json('data.id'));
        // La facture a changé pendant l'attente : ce paiement ne la solde plus
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => 30, 'quantite' => 1]);

        $this->stripe->conclure($paiement->stripe_payment_intent_id, 'succeeded');
        $this->webhook('evt_1', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();
        // Le même succès revu par la caisse, puis renvoyé par Stripe sous un autre événement
        $this->getJson("/api/v1/facturation/paiements/{$paiement->id}", $this->enTetes($this->jeton))->assertJsonPath('data.statut', 'rembourse');
        $this->webhook('evt_2', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();

        $this->assertSame([[
            'intention' => $paiement->stripe_payment_intent_id,
            'compte' => self::COMPTE,
            'centimes' => 12000,
            'cle' => 'rendu-'.$paiement->id,
        ]], $this->stripe->rendus);
        $this->assertSame('Montant différent de la facture : ce paiement a été remboursé, encaissez à nouveau.', $paiement->refresh()->erreur);
        $this->assertSame('due', $facture->refresh()->statut->value);
        $this->assertAucunMouvementSurLaPlateforme();

        // La facture s'encaisse ensuite à son nouveau montant
        $nouveau = Paiement::query()->findOrFail($this->payer($facture, 'tpe')->assertCreated()->json('data.id'));
        $this->assertSame(15000, $this->stripe->intentions[$nouveau->stripe_payment_intent_id]['centimes']);
    }

    public function test_un_remboursement_refuse_par_stripe_laisse_le_paiement_a_rembourser(): void
    {
        Exceptions::fake();
        $facture = $this->factureDue(120);
        $paiement = Paiement::query()->findOrFail($this->payer($facture, 'tpe')->json('data.id'));
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => 30, 'quantite' => 1]);
        $this->stripe->erreurs['rembourser'] = new ErreurPaiement(ErreurPaiement::GENERIQUE, 'balance_insufficient');

        $this->stripe->conclure($paiement->stripe_payment_intent_id, 'succeeded');
        $this->webhook('evt_1', 'payment_intent.succeeded', ['id' => $paiement->stripe_payment_intent_id, 'status' => 'succeeded'])->assertNoContent();

        // Demandé au bon compte, refusé : rien n'est rendu, l'erreur est signalée et notée sur le paiement
        $this->assertSame([self::COMPTE], array_column($this->stripe->appelsDe('rembourser'), 'compte'));
        $this->assertSame([], $this->stripe->rendus);
        Exceptions::assertReported(fn (ErreurPaiement $e) => $e->codeStripe === 'balance_insufficient');
        $paiement->refresh();
        $this->assertSame('reussi', $paiement->statut->value);
        $this->assertSame('À rembourser : montant différent de la facture, le remboursement automatique a échoué.', $paiement->erreur);
        // La facture n'est pas encaissée pour autant
        $this->assertSame('due', $facture->refresh()->statut->value);

        // La caisse en est alertée : le client a payé en trop
        $alertes = collect($this->getJson('/api/v1/alertes', $this->enTetes($this->jeton))->assertOk()->json('data.alertes'))->where('type', 'paiement');
        $this->assertSame(['1 paiement à rembourser'], $alertes->pluck('titre')->values()->all());
        $this->assertStringContainsString('remboursez-le depuis Réglages, Paiements', $alertes->first()['detail']);
    }

    public function test_une_intention_introuvable_chez_stripe_reste_une_erreur_lisible_pour_la_caisse(): void
    {
        $facture = $this->factureDue(80);
        $paiement = Paiement::query()->findOrFail($this->payer($facture, 'tpe')->json('data.id'));
        // Suivi sur un autre compte que celui qui l'a reçue : chez Stripe, elle n'y existe pas
        $paiement->update(['stripe_compte_id' => 'acct_autre']);

        $this->getJson("/api/v1/facturation/paiements/{$paiement->id}", $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.paiement.0', ErreurPaiement::GENERIQUE);
        $this->postJson("/api/v1/facturation/paiements/{$paiement->id}/annuler", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.paiement.0', ErreurPaiement::GENERIQUE);

        $this->assertSame('en_attente', $paiement->refresh()->statut->value);
        $this->assertSame('requires_payment_method', $this->stripe->intentions[$paiement->stripe_payment_intent_id]['statut']);
        $this->assertSame('due', $facture->refresh()->statut->value);
    }

    public function test_l_acompte_s_ouvre_se_confirme_et_se_rend_sur_le_compte_qui_l_a_recu(): void
    {
        ['jeton' => $jeton, 'demande' => $demande] = $this->deposerAvecAcompte();
        ['jeton' => $jetonClinique] = $this->employeAvecJeton($this->clinique);

        $ouverture = $this->stripe->appelsDe('creerIntentionEnLigne');
        $this->assertSame([[
            'methode' => 'creerIntentionEnLigne', 'compte' => self::COMPTE, 'objet' => null, 'cle' => 'acompte-'.$demande->id,
        ]], $ouverture);
        $this->assertSame(self::COMPTE, $demande->acompte_stripe_compte_id);
        // 0,5 % de 30 CHF
        $this->assertSame(15, $this->stripe->intentions[$demande->acompte_intention_id]['commission']);

        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk()->assertJsonPath('data.statut', 'acceptee');

        // La société change de compte de paiement : l'acompte est rendu par celui qui l'a reçu
        $this->clinique->societe->update(['stripe_compte_id' => 'acct_nouveau']);
        $this->postJson("/api/v1/rendez-vous/{$demande->refresh()->rendez_vous_id}/annuler", [], $this->enTetes($jetonClinique))->assertOk();

        $this->assertSame([[
            'intention' => $demande->acompte_intention_id,
            'compte' => self::COMPTE,
            'centimes' => 3000,
            'cle' => 'remboursement-acompte-'.$demande->id,
        ]], $this->stripe->rendus);
        $this->assertSame(
            ['creerIntentionEnLigne', 'lireIntention', 'fraisIntention', 'rembourser'],
            array_column($this->stripe->appels, 'methode'),
        );
        $this->assertSame([self::COMPTE], array_values(array_unique(array_column($this->stripe->appels, 'compte'))));
        $this->assertSame(3000, $demande->refresh()->acompte_rembourse_centimes);
    }

    public function test_rouvrir_le_paiement_d_un_acompte_rejoue_la_meme_intention(): void
    {
        ['demande' => $demande, 'paiement' => $paiement] = $this->deposerAvecAcompte();

        // Un second envoi de la même ouverture (réseau qui réessaie) : même clé, même intention
        $rejoue = app(AcomptesRendezVous::class)->ouvrir($demande);

        $this->assertSame($paiement['client_secret'], $rejoue['client_secret']);
        $this->assertCount(1, $this->stripe->intentions);
        $this->assertSame($demande->acompte_intention_id, $demande->refresh()->acompte_intention_id);
        $this->assertSame(['acompte-'.$demande->id, 'acompte-'.$demande->id], array_column($this->stripe->appelsDe('creerIntentionEnLigne'), 'cle'));
    }

    public function test_un_acompte_que_stripe_refuse_d_ouvrir_ne_laisse_aucune_demande(): void
    {
        $this->stripe->erreurs['creerIntentionEnLigne'] = new ErreurPaiement(ErreurPaiement::GENERIQUE, 'account_invalid');

        $this->deposer()
            ->assertUnprocessable()
            ->assertJsonPath('errors.paiement.0', ErreurPaiement::GENERIQUE);

        $this->assertSame(0, DemandeRendezVous::withoutGlobalScopes()->count());
        $this->assertSame(0, RendezVous::withoutGlobalScopes()->count());
        $this->assertSame([], $this->stripe->intentions);
    }

    public function test_un_acompte_paye_apres_le_refus_et_qui_ne_peut_etre_rendu_sera_rendu_au_prochain_envoi_de_stripe(): void
    {
        ['demande' => $demande] = $this->deposerAvecAcompte();
        // La clinique a clos la demande avant le paiement
        $demande->update(['statut' => 'refusee']);
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->stripe->erreurs['rembourser'] = new ErreurPaiement(ErreurPaiement::GENERIQUE, 'balance_insufficient');
        $evenement = ['id' => $demande->acompte_intention_id, 'status' => 'succeeded', 'metadata' => ['objet' => 'acompte_rdv']];

        // Le traitement échoue : l'événement est libéré pour que Stripe le renvoie
        $this->webhook('evt_acompte', 'payment_intent.succeeded', $evenement, self::COMPTE)->assertServerError();
        $this->assertFalse(EvenementStripe::query()->whereKey('evt_acompte')->exists());
        $this->assertNull($demande->refresh()->acompte_paye_le);

        // Renvoyé une fois le compte réapprovisionné : rendu une seule fois, par la même clé
        unset($this->stripe->erreurs['rembourser']);
        $this->webhook('evt_acompte', 'payment_intent.succeeded', $evenement, self::COMPTE)->assertNoContent();
        $this->webhook('evt_acompte', 'payment_intent.succeeded', $evenement, self::COMPTE)->assertNoContent();

        $this->assertSame(['remboursement-acompte-'.$demande->id, 'remboursement-acompte-'.$demande->id], array_column($this->stripe->appelsDe('rembourser'), 'cle'));
        $this->assertCount(1, $this->stripe->rendus);
        $this->assertSame(3000, $this->stripe->rendus[0]['centimes']);
        $this->assertNotNull($demande->refresh()->acompte_rembourse_le);
        $this->assertSame(0, RendezVous::withoutGlobalScopes()->count());
    }

    private function assertAucunMouvementSurLaPlateforme(): void
    {
        $surLaPlateforme = array_filter($this->stripe->appels, fn (array $appel) => $appel['compte'] === null && in_array($appel['methode'], self::MOUVEMENTS, true));

        $this->assertSame([], array_values($surLaPlateforme), 'Un paiement ou un remboursement est passé par le compte de la plateforme.');
    }

    private function factureDue(float $montant): Facture
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'prix_chf' => $montant, 'quantite' => 1]);

        return $facture;
    }

    private function payer(Facture $facture, string $moyen): TestResponse
    {
        $terminal = TerminalPaiement::query()->firstOrCreate(
            ['stripe_reader_id' => 'tmr_comptoir'],
            ['clinique_id' => $this->clinique->id, 'libelle' => 'Comptoir', 'modele' => 'bbpos_wisepos_e'],
        );

        return $this->postJson("/api/v1/facturation/factures/{$facture->id}/paiements", array_filter([
            'moyen' => $moyen,
            'terminal_id' => $moyen === 'tpe' ? $terminal->id : null,
        ]), $this->enTetes($this->jeton));
    }

    /** Dépôt sans compte, un mardi à venir à 10 h */
    private function deposer(): TestResponse
    {
        Mail::fake();
        $this->clinique->update(['acompte_centimes' => 3000, 'rdv_sans_compte' => true]);
        HoraireOuverture::factory()->create(['clinique_id' => $this->clinique->id]);
        LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        $mardi = CarbonImmutable::now('Europe/Zurich')->addDays(2)->next(CarbonImmutable::TUESDAY)->setTime(10, 0);

        return $this->postJson('/api/v1/public/demandes', [
            'code' => $this->codeDeDemande($this->clinique->id, 'pineau@exemple.ch'),
            'clinique_id' => $this->clinique->id, 'patient_name' => 'Louison', 'espece' => 'CH', 'nom_proprietaire' => 'Mme Pineau',
            'email' => 'pineau@exemple.ch', 'telephone' => '079 512 44 08', 'motif' => 'Vaccin annuel', 'mode' => 'place',
            'debut_souhaite' => $mardi->toIso8601String(),
        ]);
    }

    /** @return array{jeton: string, demande: DemandeRendezVous, paiement: array<string, mixed>} */
    private function deposerAvecAcompte(): array
    {
        $reponse = $this->deposer()->assertCreated()->assertJsonPath('data.statut', 'a_payer');

        return [
            'jeton' => $reponse->json('data.jeton'),
            'demande' => DemandeRendezVous::withoutGlobalScopes()->sole(),
            'paiement' => $reponse->json('data.paiement'),
        ];
    }

    /** @param  array<string, mixed>  $objet */
    private function webhook(string $id, string $type, array $objet, ?string $compte = null): TestResponse
    {
        return $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'signature-valide',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(array_filter(['id' => $id, 'type' => $type, 'account' => $compte, 'data' => ['object' => $objet]])));
    }
}
