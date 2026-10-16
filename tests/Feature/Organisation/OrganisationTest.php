<?php

namespace Tests\Feature\Organisation;

use App\Auth\RolesParDefaut;
use App\Enums\FormuleSaas;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\OrganisationFacture;
use App\Models\Role;
use App\Models\Societe;
use App\Paiements\PasserelleAbonnement;
use App\Paiements\PasserellePaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Structure et abonnement SaaS : sociétés, cliniques (création avec
 * horaires par défaut, quota de la formule, suppression tolérée d'une
 * clinique vide seulement — jamais la dernière), changement de formule
 * bloqué par les limites dépassées) et abonnement réel chez Stripe
 * Billing (double en mémoire) : carte, souscription, prorata, refus,
 * résiliation, packs prélevés, factures reflétées, webhooks.
 */
class OrganisationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private PasserelleAbonnementFactice $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();

        ['employe' => $employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

        $employe->update([
            'role_id' => Role::withoutGlobalScope('organisation')
                ->where('organisation_id', $this->organisationId())
                ->where('nom', RolesParDefaut::ADMINISTRATEUR)
                ->value('id'),
        ]);

        $this->stripe = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $this->stripe);
    }

    /** Carte enregistrée comme le ferait le Payment Element : intention, confirmation, enregistrement. */
    private function avecCarte(string $fin = '4242'): void
    {
        $entetes = $this->enTetes($this->jeton);
        $this->postJson('/api/v1/organisation/moyen-paiement/intention', [], $entetes)->assertOk();
        $client = Organisation::query()->findOrFail($this->organisationId())->stripe_client_id;
        $this->putJson('/api/v1/organisation/moyen-paiement', ['payment_method' => $this->stripe->carteConfirmee($client, 'visa', $fin)], $entetes)->assertOk();
    }

    private function organisationId(): int
    {
        return $this->clinique->societe->organisation_id;
    }

    public function test_l_organisation_expose_formule_limites_usage_et_stats(): void
    {
        $this->getJson('/api/v1/organisation', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.plan.code', 'cabinet')
            ->assertJsonPath('data.plan.limites.cliniques', 5)
            ->assertJsonPath('data.plan.limites.employes', 30)
            ->assertJsonPath('data.stats.cliniques', 1)
            ->assertJsonPath('data.stats.employes_actifs', 1);
    }

    public function test_une_societe_s_ajoute_avec_un_ide_genere_au_besoin(): void
    {
        $reponse = $this->postJson('/api/v1/organisation/societes', [
            'nom' => 'Vet Riviera Sàrl',
        ], $this->enTetes($this->jeton));

        $reponse->assertCreated()->assertJsonPath('data.nom', 'Vet Riviera Sàrl');

        $this->assertMatchesRegularExpression('/^CHE-\d{3}\.\d{3}\.\d{3}$/', $reponse->json('data.ide'));
    }

    public function test_une_clinique_se_cree_avec_ses_horaires_par_defaut(): void
    {
        $reponse = $this->postJson('/api/v1/organisation/cliniques', [
            'nom' => 'Riviera',
            'raison_sociale' => 'Clinique Vétérinaire de la Riviera',
            'societe_id' => $this->clinique->societe_id,
            'rue' => 'Avenue de Chillon 12',
            'npa' => '1820',
            'ville' => 'Montreux',
        ], $this->enTetes($this->jeton));

        $reponse->assertCreated()->assertJsonPath('data.nom', 'Riviera');

        $this->assertDatabaseHas('horaires_ouverture', [
            'clinique_id' => $reponse->json('data.id'),
            'ouverture' => '08:00',
            'fermeture' => '19:30',
        ]);
    }

    public function test_la_creation_de_clinique_respecte_la_limite_de_la_formule(): void
    {
        Clinique::withoutGlobalScopes()->findOrFail($this->clinique->id)
            ->societe->organisation->update(['plan' => FormuleSaas::Individuel]);

        $this->postJson('/api/v1/organisation/cliniques', [
            'nom' => 'Refusée',
            'raison_sociale' => 'Clinique de trop',
            'societe_id' => $this->clinique->societe_id,
            'rue' => 'Rue X 1',
            'npa' => '1000',
            'ville' => 'Lausanne',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliniques');
    }

    public function test_une_clinique_se_modifie_et_se_rattache_a_une_autre_societe(): void
    {
        $autreSociete = Societe::create([
            'organisation_id' => $this->organisationId(),
            'nom' => 'Vet Lac Léman Sàrl',
            'ide' => 'CHE-302.118.447',
        ]);

        $this->patchJson("/api/v1/organisation/cliniques/{$this->clinique->id}", [
            'tel' => '+41 21 601 42 42',
            'societe_id' => $autreSociete->id,
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.tel', '+41 21 601 42 42')
            ->assertJsonPath('data.societe_id', $autreSociete->id);
    }

    public function test_la_derniere_clinique_ne_se_supprime_jamais(): void
    {
        $this->deleteJson("/api/v1/organisation/cliniques/{$this->clinique->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clinique');
    }

    public function test_une_clinique_avec_historique_se_desactive_plutot_qu_elle_ne_se_supprime(): void
    {
        $avecEquipe = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        Employe::factory()->create(['clinique_id' => $avecEquipe->id]);

        $this->deleteJson("/api/v1/organisation/cliniques/{$avecEquipe->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable();

        $vide = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        $this->deleteJson("/api/v1/organisation/cliniques/{$vide->id}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertDatabaseMissing('cliniques', ['id' => $vide->id]);
    }

    public function test_le_changement_de_formule_est_bloque_par_les_limites_depassees(): void
    {
        // Deux cliniques : la formule Individuel (1 clinique) est hors
        // de portée — le message nomme le dépassement.
        Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $this->avecCarte();

        $this->postJson('/api/v1/organisation/abonnement', [
            'plan' => 'individuel',
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan');
        $this->assertSame([], $this->stripe->abonnements);
    }

    public function test_la_carte_s_enregistre_chez_stripe_et_seul_son_libelle_est_garde(): void
    {
        $entetes = $this->enTetes($this->jeton);

        $intention = $this->postJson('/api/v1/organisation/moyen-paiement/intention', [], $entetes)
            ->assertOk()
            ->assertJsonStructure(['data' => ['client_secret', 'cle_publique']])
            ->json('data');
        $this->assertStringStartsWith('seti_secret_', $intention['client_secret']);
        $client = Organisation::query()->findOrFail($this->organisationId())->stripe_client_id;
        $this->assertNotNull($client);

        // Une carte d'un autre client Stripe est refusée
        $etrangere = $this->stripe->carteConfirmee($this->stripe->creerClient('Autre', null, []));
        $this->putJson('/api/v1/organisation/moyen-paiement', ['payment_method' => $etrangere], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $premiere = $this->stripe->carteConfirmee($client, 'visa', '4242');
        $this->putJson('/api/v1/organisation/moyen-paiement', ['payment_method' => $premiere], $entetes)
            ->assertOk()->assertJsonPath('data.moyen_paiement', 'Visa •••• 4242 · exp. 08/28');
        $this->assertSame($premiere, $this->stripe->clients[$client]['carte']);

        // Remplacée : l'ancienne est détachée chez Stripe
        $seconde = $this->stripe->carteConfirmee($client, 'mastercard', '4444');
        $this->putJson('/api/v1/organisation/moyen-paiement', ['payment_method' => $seconde], $entetes)
            ->assertOk()->assertJsonPath('data.moyen_paiement', 'Mastercard •••• 4444 · exp. 08/28');
        $this->assertSame([$premiere], $this->stripe->detachees);

        $this->deleteJson('/api/v1/organisation/moyen-paiement', [], $entetes)
            ->assertOk()->assertJsonPath('data.moyen_paiement', null);
        $this->assertContains($seconde, $this->stripe->detachees);
    }

    public function test_la_souscription_exige_une_carte(): void
    {
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('moyen_paiement');
    }

    public function test_la_souscription_cree_l_abonnement_stripe_et_reflete_sa_facture(): void
    {
        $this->avecCarte();

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital', 'cycle' => 'an'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.plan.code', 'hopital')
            ->assertJsonPath('data.cycle', 'an')
            ->assertJsonPath('data.abonnement.statut', 'actif')
            ->assertJsonPath('data.abonnement.periode_fin', now()->addMonth()->toDateString())
            ->assertJsonPath('data.resilie_le', null);

        // Un an = douze mois, sans remise, HT : 999 × 12 ; TVA 8,1 % en sus
        $this->assertSame(1198800, $this->stripe->prix['veterinary-hopital-an']);
        $this->getJson('/api/v1/organisation/factures', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.libelle', 'Abonnement Pro Hôpital, annuel')
            ->assertJsonPath('data.0.statut', 'payee')
            ->assertJsonPath('data.0.montant_ht', 11988)
            ->assertJsonPath('data.0.tva', 971.03)
            ->assertJsonPath('data.0.montant', 12959.03)
            ->assertJsonPath('data.0.pdf_url', fn (string $url): bool => str_starts_with($url, 'https://pay.stripe.com/'));
    }

    public function test_le_changement_de_formule_est_proratise_sur_l_abonnement_en_cours(): void
    {
        $this->avecCarte();
        $entetes = $this->enTetes($this->jeton);
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet', 'cycle' => 'mois'], $entetes)->assertOk();
        $abonnement = Organisation::query()->findOrFail($this->organisationId())->stripe_abonnement_id;

        // La formule en cours n'est pas « rachetée »
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet', 'cycle' => 'mois'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('plan');

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital', 'cycle' => 'mois'], $entetes)
            ->assertOk()->assertJsonPath('data.plan.code', 'hopital');
        $this->assertCount(1, $this->stripe->abonnements, 'même abonnement, nouvelle formule');
        $this->assertSame('price_veterinary-hopital-mois', $this->stripe->abonnements[$abonnement]['prix']);
        $this->assertSame('Passage à Pro Hôpital, mensuel (prorata)',
            $this->getJson('/api/v1/organisation/factures', $entetes)->json('data.0.libelle'));
    }

    public function test_une_carte_refusee_ne_change_rien(): void
    {
        $this->avecCarte();
        $this->stripe->refus = 'La carte a été refusée par la banque.';

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.paiement.0', 'La carte a été refusée par la banque.');
        $this->assertDatabaseHas('organisations', ['id' => $this->organisationId(), 'plan' => 'cabinet', 'statut_abonnement' => null]);
    }

    public function test_la_resiliation_vaut_fin_de_periode_et_se_reactive(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('abonnement');

        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $entetes)->assertOk();

        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.resilie_le', now()->addMonth()->toDateString())
            ->assertJsonPath('data.abonnement.statut', 'actif');

        $this->postJson('/api/v1/organisation/abonnement/reactiver', [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.resilie_le', null);
    }

    public function test_un_pack_de_quota_est_preleve_puis_ajoute(): void
    {
        $entetes = $this->enTetes($this->jeton);
        $this->postJson('/api/v1/organisation/packs', ['type' => 'emails', 'quantite' => 5000], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('moyen_paiement');

        $this->avecCarte();
        $this->postJson('/api/v1/organisation/packs', ['type' => 'emails', 'quantite' => 5000], $entetes)
            ->assertOk()
            ->assertJsonPath('data.quota_extra.emails', 5000);
        $this->assertDatabaseHas('organisation_factures', [
            'libelle' => 'Quota e-mails +5 000',
            'montant_ht' => 15,
            'montant' => 16.22,
            'quota_applique' => true,
        ]);

        // Refusé : ni facture ni quota en plus
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $this->postJson('/api/v1/organisation/packs', ['type' => 'sms', 'quantite' => 500], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('paiement');
        $this->assertDatabaseHas('organisations', ['id' => $this->organisationId(), 'quota_extra_sms' => 0]);

        // Pas de pack sur un quota que la formule rend illimité
        $this->stripe->refus = null;
        Organisation::query()->whereKey($this->organisationId())->update(['plan' => 'hopital']);
        $this->postJson('/api/v1/organisation/packs', ['type' => 'emails', 'quantite' => 5000], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        Organisation::query()->whereKey($this->organisationId())->update(['plan' => 'cabinet']);

        // Un palier hors catalogue est refusé — le prix est celui du
        // serveur, jamais celui du client.
        $this->postJson('/api/v1/organisation/packs', ['type' => 'emails', 'quantite' => 4242], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantite');
    }

    public function test_les_webhooks_tiennent_l_abonnement_et_les_factures_a_jour(): void
    {
        $this->app->instance(PasserellePaiement::class, new PasserellePaiementFactice);
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $abonnement = $organisation->stripe_abonnement_id;
        $webhook = fn (string $id, string $type, array $objet) => $this->postJson('/api/v1/stripe/webhook', ['id' => $id, 'type' => $type, 'data' => ['object' => $objet]], ['Stripe-Signature' => 'signature-valide'])->assertNoContent();

        // Renouvellement impayé : l'abonnement passe en impayé, la facture est à payer
        $this->stripe->abonnements[$abonnement]['status'] = 'past_due';
        $webhook('evt_1', 'customer.subscription.updated', ['id' => $abonnement, 'customer' => $organisation->stripe_client_id]);
        $facture = $this->stripe->facturerAchat($organisation->stripe_client_id, 24900, 'Renouvellement', 'txr_tva', [], 'x');
        $this->stripe->factures[$facture['id']]['status'] = 'open';
        $this->stripe->factures[$facture['id']]['billing_reason'] = 'subscription_cycle';
        $webhook('evt_2', 'invoice.payment_failed', ['id' => $facture['id']]);

        $this->assertDatabaseHas('organisations', ['id' => $organisation->id, 'statut_abonnement' => 'impaye']);
        $this->assertDatabaseHas('organisation_factures', ['stripe_facture_id' => $facture['id'], 'statut' => 'a_payer', 'paiement_url' => 'https://invoice.stripe.com/i/'.$facture['id']]);

        // Réglée sur la page Stripe : payée, plus de lien de paiement ; l'abonnement redevient actif
        $this->stripe->factures[$facture['id']]['status'] = 'paid';
        $this->stripe->abonnements[$abonnement]['status'] = 'active';
        $webhook('evt_3', 'invoice.paid', ['id' => $facture['id']]);
        $webhook('evt_4', 'customer.subscription.updated', ['id' => $abonnement, 'customer' => $organisation->stripe_client_id]);
        $this->assertDatabaseHas('organisation_factures', ['stripe_facture_id' => $facture['id'], 'statut' => 'payee', 'paiement_url' => null]);
        $this->assertDatabaseHas('organisations', ['id' => $organisation->id, 'statut_abonnement' => 'actif']);

        // Fin de l'abonnement : résilié, l'accès finit à la date servie
        $this->stripe->abonnements[$abonnement]['status'] = 'canceled';
        $this->stripe->abonnements[$abonnement]['ended_at'] = today()->timestamp;
        $webhook('evt_5', 'customer.subscription.deleted', ['id' => $abonnement, 'customer' => $organisation->stripe_client_id]);
        $organisation->refresh();
        $this->assertSame('resilie', $organisation->statut_abonnement);
        $this->assertSame(today()->toDateString(), $organisation->resilie_le->toDateString());
    }

    public function test_les_anciennes_factures_simulees_ne_sont_plus_servies(): void
    {
        OrganisationFacture::factory()->create(['organisation_id' => $this->organisationId(), 'stripe_facture_id' => null, 'numero' => 'F-2026-070']);

        $this->getJson('/api/v1/organisation/factures', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
