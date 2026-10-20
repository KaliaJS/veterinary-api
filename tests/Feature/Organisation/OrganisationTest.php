<?php

namespace Tests\Feature\Organisation;

use App\Auth\ContexteClinique;
use App\Auth\EmetteurJetons;
use App\Auth\RolesParDefaut;
use App\Enums\FormuleSaas;
use App\Mail\CarteExpireAvantPrelevement;
use App\Mail\PaiementAbonnementRefuse;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\OrganisationFacture;
use App\Models\Role;
use App\Models\Societe;
use App\Models\User;
use App\Paiements\PasserelleAbonnement;
use App\Paiements\PasserellePaiement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Structure et abonnement SaaS : sociétés, cliniques (création avec
 * horaires par défaut, quota de la formule, suppression tolérée d'une
 * clinique vide seulement — jamais la dernière), changement de formule
 * bloqué par les limites dépassées) et abonnement maison (Stripe en
 * double, pour la carte et les prélèvements) : souscription, date
 * anniversaire, formule plus chère au prorata ou moins chère au
 * renouvellement, refus et relances, confirmation bancaire, résiliation,
 * packs, factures sans TVA.
 */
class OrganisationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    private User $user;

    private PasserelleAbonnementFactice $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();

        ['employe' => $employe, 'user' => $this->user, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);

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

    /** L'organisation de la fabrique est abonnée : on repart d'une organisation qui ne l'a jamais été. */
    private function jamaisAbonnee(): void
    {
        Organisation::query()->whereKey($this->organisationId())
            ->update(['statut_abonnement' => null, 'periode_debut' => null, 'periode_fin' => null, 'ancre_jour' => null]);
    }

    /** Voyage dans le temps, avec un jeton neuf (les jetons expirent) */
    private function voyager(string $quand): void
    {
        $this->travelTo(CarbonImmutable::parse($quand, 'Europe/Zurich'));
        app(ContexteClinique::class)->oublier();
        $this->jeton = app(EmetteurJetons::class)->emettre($this->user)['jeton']->plainTextToken;
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
        Employe::factory()->dans($avecEquipe)->create();

        $this->deleteJson("/api/v1/organisation/cliniques/{$avecEquipe->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable();

        $vide = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        $this->deleteJson("/api/v1/organisation/cliniques/{$vide->id}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertDatabaseMissing('cliniques', ['id' => $vide->id]);
    }

    public function test_le_solde_de_conges_compte_aussi_les_conges_des_employes_des_autres_cliniques(): void
    {
        $autre = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $employe = Employe::factory()->dans($autre)->create(['conges_droit' => 25, 'conges_pris' => 5]);
        Absence::factory()->create([
            'employe_id' => $employe->id,
            'debut_le' => today()->addDays(10),
            'fin_le' => today()->addDays(14),
        ]);

        $equipe = collect($this->getJson('/api/v1/organisation/cliniques', $this->enTetes($this->jeton))->assertOk()->json('data'))
            ->firstWhere('id', $autre->id)['equipe'];

        $this->assertSame(['droit' => 25, 'pris' => 5, 'planifies' => 5, 'solde' => 15], $equipe[0]['conges']);
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
        $this->assertSame([], $this->stripe->prelevements);
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
        $this->jamaisAbonnee();

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('moyen_paiement');
    }

    public function test_la_souscription_preleve_le_premier_mois_et_emet_notre_facture_sans_tva(): void
    {
        $this->jamaisAbonnee();

        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('paiement', null)
            ->assertJsonPath('data.plan.code', 'hopital')
            ->assertJsonPath('data.cycle', 'mois')
            ->assertJsonPath('data.abonnement.statut', 'actif')
            ->assertJsonPath('data.abonnement.periode_debut', '2026-10-20')
            ->assertJsonPath('data.abonnement.periode_fin', '2026-11-20')
            ->assertJsonPath('data.resilie_le', null);

        $prelevement = $this->stripe->dernierPrelevement();
        $this->assertSame(99900, $prelevement['centimes']);
        $this->assertFalse($prelevement['hors_session']);
        $this->assertSame('veterinary', $prelevement['metadonnees']['plateforme']);

        $liste = $this->getJson('/api/v1/organisation/factures', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero', 'MS-2026-00001')
            ->assertJsonPath('data.0.libelle', 'Abonnement Pro Hôpital, du 20.10.2026 au 19.11.2026')
            ->assertJsonPath('data.0.statut', 'payee')
            ->assertJsonPath('data.0.montant', 999)
            ->assertJsonPath('data.0.carte', 'Visa •••• 4242 · exp. 08/28')
            ->assertJsonPath('data.0.periode_fin', '2026-11-19');

        $this->getJson('/api/v1/organisation/factures/'.$liste->json('data.0.id'), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.emetteur.nom', 'Meteora Systems Sàrl')
            ->assertJsonPath('data.emetteur.ide', 'CHE-422.618.848')
            ->assertJsonPath('data.emetteur.assujetti_tva', false)
            ->assertJsonPath('data.lignes.0.montant', 999)
            ->assertJsonPath('data.client.nom', $this->clinique->societe->nom);
    }

    public function test_le_renouvellement_suit_la_date_anniversaire_comme_netflix(): void
    {
        $this->jamaisAbonnee();

        // Souscrit un 31 : février finit le 28, mars revient au 31
        $this->voyager('2027-01-31 09:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))
            ->assertJsonPath('data.abonnement.periode_fin', '2027-02-28');

        $this->voyager('2027-02-28 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame('2027-02-28', $organisation->periode_debut->toDateString());
        $this->assertSame('2027-03-31', $organisation->periode_fin->toDateString());

        $renouvellement = $this->stripe->dernierPrelevement();
        $this->assertSame(24900, $renouvellement['centimes'], 'le même prix, quelle que soit la longueur du mois');
        $this->assertTrue($renouvellement['hors_session']);

        // Relancé dans l'heure : rien n'est prélevé deux fois
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertCount(2, $this->stripe->prelevements);
    }

    public function test_une_formule_plus_chere_part_aussitot_avec_le_credit_du_temps_non_utilise(): void
    {
        $this->jamaisAbonnee();

        $this->voyager('2026-10-01 10:00');
        $entetes = $this->enTetes($this->jeton);
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $entetes)->assertOk();

        // La formule en cours n'est pas « rachetée »
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('plan');

        // 11 jours non utilisés sur 31 : crédit de 249 × 11 / 31 = 88,35 CHF
        $this->voyager('2026-10-21 10:00');
        $this->getJson('/api/v1/organisation/abonnement/apercu?plan=hopital', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.quand', 'maintenant')
            ->assertJsonPath('data.total', 910.65)
            ->assertJsonPath('data.lignes.1.montant', -88.35);
        $this->getJson('/api/v1/organisation/abonnement/apercu?plan=individuel', $this->enTetes($this->jeton))
            ->assertJsonPath('data.quand', 'renouvellement')
            ->assertJsonPath('data.date', '2026-11-01');
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.plan.code', 'hopital')
            ->assertJsonPath('data.abonnement.periode_debut', '2026-10-21')
            ->assertJsonPath('data.abonnement.periode_fin', '2026-11-21');

        $this->assertSame(99900 - 8835, $this->stripe->dernierPrelevement()['centimes']);
        $this->getJson('/api/v1/organisation/factures', $this->enTetes($this->jeton))
            ->assertJsonPath('data.0.lignes.0.libelle', 'Abonnement Pro Hôpital, du 21.10.2026 au 20.11.2026')
            ->assertJsonPath('data.0.lignes.1.libelle', 'Crédit Pro Cabinet non utilisé (11 jours)')
            ->assertJsonPath('data.0.lignes.1.montant', -88.35)
            ->assertJsonPath('data.0.montant', 910.65);
    }

    public function test_une_formule_moins_chere_s_applique_au_renouvellement(): void
    {
        $this->jamaisAbonnee();

        $this->voyager('2026-10-20 10:00');
        $entetes = $this->enTetes($this->jeton);
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $entetes)->assertOk();

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $entetes)
            ->assertOk()
            ->assertJsonPath('data.plan.code', 'hopital')
            ->assertJsonPath('data.abonnement.formule_suivante.code', 'cabinet');
        $this->assertCount(1, $this->stripe->prelevements, 'rien n’est prélevé ni remboursé');

        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame(24900, $this->stripe->dernierPrelevement()['centimes']);
        $this->assertDatabaseHas('organisations', ['id' => $this->organisationId(), 'plan' => 'cabinet', 'formule_suivante' => null]);
    }

    public function test_une_carte_refusee_ne_change_rien(): void
    {
        $this->jamaisAbonnee();

        $this->avecCarte();
        $this->stripe->refus = 'La carte a été refusée par la banque.';

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonPath('errors.paiement.0', 'La carte a été refusée par la banque.');
        $this->assertDatabaseHas('organisations', ['id' => $this->organisationId(), 'plan' => 'cabinet', 'statut_abonnement' => null]);
        $this->getJson('/api/v1/organisation/factures', $this->enTetes($this->jeton))->assertJsonCount(0, 'data');

        // Une erreur du prestataire ne laisse pas de facture en attente
        $this->stripe->refus = null;
        $this->stripe->panne = 'Le prestataire de paiement a refusé l’opération.';
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('paiement');
        $this->assertSame(0, OrganisationFacture::query()->where('statut', 'a_payer')->count());
    }

    public function test_une_confirmation_bancaire_active_l_abonnement_une_fois_faite(): void
    {
        $this->jamaisAbonnee();

        $entetes = $this->enTetes($this->jeton);
        $this->avecCarte();
        $this->stripe->actionRequise = true;

        $reponse = $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $entetes)
            ->assertOk()
            ->assertJsonPath('data.abonnement.statut', null)
            ->assertJsonPath('paiement.client_secret', fn (string $secret) => str_ends_with($secret, '_secret'));

        $facture = $reponse->json('paiement.facture_id');
        $this->stripe->confirmer($this->stripe->dernierPrelevement()['id']);
        $this->postJson("/api/v1/organisation/factures/{$facture}/confirmer", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.statut', 'payee')
            ->assertJsonPath('paiement', null);
        $this->assertDatabaseHas('organisations', ['id' => $this->organisationId(), 'statut_abonnement' => 'actif', 'plan' => 'cabinet']);
    }

    public function test_le_webhook_d_un_paiement_confirme_applique_la_facture(): void
    {
        $this->jamaisAbonnee();

        $this->app->instance(PasserellePaiement::class, new PasserellePaiementFactice);
        $this->avecCarte();
        $this->stripe->actionRequise = true;
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $prelevement = $this->stripe->dernierPrelevement();

        $this->postJson('/api/v1/stripe/webhook', ['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => $prelevement['id'], 'status' => 'succeeded', 'metadata' => $prelevement['metadonnees'],
        ]]], ['Stripe-Signature' => 'signature-valide'])->assertNoContent();

        $this->assertDatabaseHas('organisations', ['id' => $this->organisationId(), 'statut_abonnement' => 'actif']);
    }

    /** Abonnée le 20.10.2026 à Cabinet, carte enregistrée, le renouvellement du 20.11 sera refusé. */
    private function abonneeAvecRenouvellementRefuse(): void
    {
        $this->jamaisAbonnee();
        Mail::fake();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
    }

    public function test_un_renouvellement_refuse_se_retente_chaque_jour_puis_ferme_l_acces_au_bout_de_trois_jours(): void
    {
        $this->abonneeAvecRenouvellementRefuse();

        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame('impaye', $organisation->statut_abonnement);
        $this->assertSame('2026-11-21', $organisation->prochaine_tentative_le->timezone('Europe/Zurich')->toDateString());
        Mail::assertSent(PaiementAbonnementRefuse::class, fn ($mail) => $mail->prochaineTentative !== null && $mail->fermetureLe?->toDateString() === '2026-11-23');

        // Pendant les relances, l'accès reste ouvert et la date de fermeture est connue
        $entetes = $this->enTetes($this->jeton);
        $this->getJson('/api/v1/patients', $entetes)->assertOk();
        $this->getJson('/api/v1/organisation', $entetes)
            ->assertJsonPath('data.abonnement.statut', 'impaye')
            ->assertJsonPath('data.abonnement.fermeture_le', '2026-11-23');
        $this->getJson('/api/v1/organisation/factures', $entetes)->assertJsonPath('data.0.statut', 'a_payer');

        // Une tentative par jour : encore refusée le 21 et le 22, l'accès tient
        foreach (['2026-11-21 07:00', '2026-11-22 07:00'] as $quand) {
            $this->travelTo(CarbonImmutable::parse($quand, 'Europe/Zurich'));
            $this->artisan('abonnements:renouveler')->assertSuccessful();
            $this->assertSame('impaye', $organisation->refresh()->statut_abonnement);
        }

        // Le 23, la dernière est refusée : l'accès se ferme, la période qui ne commence pas est annulée
        $this->voyager('2026-11-23 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $organisation->refresh();
        $this->assertSame('resilie', $organisation->statut_abonnement);
        $this->assertCount(5, $this->stripe->prelevements);
        $this->assertSame('annulee', OrganisationFacture::query()->latest('id')->value('statut'));
        Mail::assertSent(PaiementAbonnementRefuse::class, fn ($mail) => $mail->prochaineTentative === null && $mail->fermetureLe === null);

        $entetes = $this->enTetes($this->jeton);
        $this->getJson('/api/v1/patients', $entetes)->assertForbidden()->assertJsonPath('code', 'abonnement_requis');

        // Une reprise ouvre une nouvelle période, le jour du paiement
        $this->stripe->refus = null;
        $this->voyager('2026-11-25 10:00');
        $entetes = $this->enTetes($this->jeton);
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $entetes)->assertOk();
        $organisation->refresh();
        $this->assertSame('actif', $organisation->statut_abonnement);
        $this->assertSame('2026-11-25', $organisation->periode_debut->toDateString());
        $this->assertSame('2026-12-25', $organisation->periode_fin->toDateString());
        $this->assertSame(25, $organisation->ancre_jour);
    }

    public function test_paye_pendant_les_relances_la_periode_commence_quand_meme_a_l_echeance(): void
    {
        $this->abonneeAvecRenouvellementRefuse();

        $this->stripe->refus = null;
        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:00', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame('actif', $organisation->statut_abonnement);
        $this->assertSame('2026-11-20', $organisation->periode_debut->toDateString());
        $this->assertSame('2026-12-20', $organisation->periode_fin->toDateString());
        $this->assertSame(20, $organisation->ancre_jour);
        $this->assertSame(0, $organisation->tentatives);
    }

    public function test_une_carte_qui_expire_avant_le_prelevement_est_signalee_14_jours_avant_puis_la_veille(): void
    {
        $this->jamaisAbonnee();
        Mail::fake();
        $this->voyager('2026-10-20 10:00');
        $entetes = $this->enTetes($this->jeton);
        $this->postJson('/api/v1/organisation/moyen-paiement/intention', [], $entetes)->assertOk();
        $client = Organisation::query()->findOrFail($this->organisationId())->stripe_client_id;
        $carte = $this->stripe->carteConfirmee($client);
        $this->stripe->cartes[$carte]['card']['exp_month'] = 10;
        $this->stripe->cartes[$carte]['card']['exp_year'] = 2026;
        $this->putJson('/api/v1/organisation/moyen-paiement', ['payment_method' => $carte], $entetes)->assertOk();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $entetes)
            ->assertOk()
            ->assertJsonPath('data.abonnement.carte_expire_le', '2026-10-31');

        $alertes = fn () => Mail::sent(CarteExpireAvantPrelevement::class)->count();
        foreach (['2026-11-05 08:00' => 0, '2026-11-06 08:00' => 1, '2026-11-06 15:00' => 1, '2026-11-12 08:00' => 1, '2026-11-19 08:00' => 2, '2026-11-19 20:00' => 2] as $quand => $attendues) {
            $this->travelTo(CarbonImmutable::parse($quand, 'Europe/Zurich'));
            $this->artisan('abonnements:renouveler')->assertSuccessful();
            $this->assertSame($attendues, $alertes(), "Alertes envoyées le $quand");
        }

        // Une nouvelle carte efface l'alerte : plus rien à signaler
        $this->voyager('2026-11-19 21:00');
        $this->avecCarte('1881');
        $this->assertNull(Organisation::query()->findOrFail($this->organisationId())->carte_alertee_le);
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame(2, $alertes());
    }

    public function test_une_carte_remplacee_par_la_banque_est_mise_a_jour(): void
    {
        $this->jamaisAbonnee();
        $this->app->instance(PasserellePaiement::class, new PasserellePaiementFactice);
        $this->avecCarte();
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $organisation->forceFill(['carte_alertee_le' => '2026-10-01'])->save();

        $this->postJson('/api/v1/stripe/webhook', ['id' => 'evt_carte', 'type' => 'payment_method.automatically_updated', 'data' => ['object' => [
            'id' => $organisation->stripe_moyen_paiement_id,
            'card' => ['brand' => 'visa', 'last4' => '5556', 'exp_month' => 9, 'exp_year' => 2031],
        ]]], ['Stripe-Signature' => 'signature-valide'])->assertNoContent();

        $organisation->refresh();
        $this->assertSame('Visa •••• 5556 · exp. 09/31', $organisation->moyen_paiement);
        $this->assertSame('2031-09-30', $organisation->carte_expire_le->toDateString());
        $this->assertNull($organisation->carte_alertee_le);
    }

    public function test_une_facture_refusee_se_regle_depuis_l_application(): void
    {
        $this->jamaisAbonnee();

        Mail::fake();
        $this->voyager('2026-10-20 10:00');
        $entetes = $this->enTetes($this->jeton);
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $entetes)->assertOk();
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        // Nouvelle carte, puis « Régler »
        $this->stripe->refus = null;
        $this->avecCarte('5556');
        $facture = $this->getJson('/api/v1/organisation/factures', $this->enTetes($this->jeton))->json('data.0.id');
        $this->postJson("/api/v1/organisation/factures/{$facture}/regler", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'payee');
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame('actif', $organisation->statut_abonnement);
        $this->assertSame('2026-12-20', $organisation->periode_fin->toDateString());
        $this->assertSame(0, $organisation->tentatives);
    }

    public function test_la_resiliation_vaut_fin_de_periode_et_se_reactive(): void
    {
        $this->jamaisAbonnee();

        // Sans abonnement, l'organisation est fermée : rien à résilier
        $entetes = $this->enTetes($this->jeton);
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $entetes)
            ->assertForbidden()->assertJsonPath('code', 'abonnement_requis');

        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $entetes)->assertOk();

        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.resilie_le', '2026-11-20')
            ->assertJsonPath('data.abonnement.statut', 'actif');

        $this->postJson('/api/v1/organisation/abonnement/reactiver', [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.resilie_le', null);

        // Résilié de nouveau : à l'échéance, plus rien n'est prélevé
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $entetes)->assertOk();
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertDatabaseHas('organisations', ['id' => $this->organisationId(), 'statut_abonnement' => 'resilie']);
        $this->assertCount(1, $this->stripe->prelevements);
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
        // Sans TVA : Meteora Systems Sàrl n'y est pas assujettie
        $this->assertDatabaseHas('organisation_factures', [
            'libelle' => 'Quota e-mails +5 000',
            'montant' => 15,
            'tva' => null,
            'statut' => 'payee',
            'quota_applique' => true,
        ]);

        // Refusé : ni quota en plus, ni facture servie
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $this->postJson('/api/v1/organisation/packs', ['type' => 'sms', 'quantite' => 500], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors('paiement');
        $this->assertDatabaseHas('organisations', ['id' => $this->organisationId(), 'quota_extra_sms' => 0]);
        $this->getJson('/api/v1/organisation/factures', $entetes)->assertJsonCount(1, 'data');

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

    public function test_les_anciennes_factures_simulees_ne_sont_plus_servies(): void
    {
        OrganisationFacture::factory()->create(['organisation_id' => $this->organisationId(), 'stripe_facture_id' => null, 'numero' => 'F-2026-070']);

        $this->getJson('/api/v1/organisation/factures', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
