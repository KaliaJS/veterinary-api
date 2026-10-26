<?php

namespace Tests\Feature\Organisation;

use App\Auth\ContexteClinique;
use App\Auth\EmetteurJetons;
use App\Auth\RolesParDefaut;
use App\Enums\FormuleSaas;
use App\Mail\CarteExpireAvantPrelevement;
use App\Mail\CleStripeRefusee;
use App\Mail\PaiementAbonnementRefuse;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\OrganisationFacture;
use App\Models\Role;
use App\Models\Societe;
use App\Models\User;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserelleAbonnement;
use App\Paiements\PasserellePaiement;
use App\Services\Abonnements\AbonnementSaas;
use App\Services\Abonnements\AlerteCleStripe;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
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

    public function test_une_societe_sans_ide_reste_sans_numero(): void
    {
        $this->postJson('/api/v1/organisation/societes', [
            'nom' => 'Vet Riviera Sàrl',
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.nom', 'Vet Riviera Sàrl')
            ->assertJsonPath('data.ide', null);
    }

    public function test_un_ide_au_chiffre_de_controle_faux_est_refuse(): void
    {
        // CHE-422.618.848 existe (Meteora Systems) ; …849 n'existe pas
        $this->postJson('/api/v1/organisation/societes', ['nom' => 'A', 'ide' => 'CHE-422.618.849'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('ide');
        $this->postJson('/api/v1/organisation/societes', ['nom' => 'B', 'ide' => 'CHE-422.618.848'], $this->enTetes($this->jeton))
            ->assertCreated()->assertJsonPath('data.ide', 'CHE-422.618.848');
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['clinique' => 'Cette clinique porte un historique, elle se désactive, elle ne se supprime pas.']);

        $vide = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        $this->deleteJson("/api/v1/organisation/cliniques/{$vide->id}", [], $this->enTetes($this->jeton))
            ->assertNoContent();

        $this->assertDatabaseMissing('cliniques', ['id' => $vide->id]);
    }

    /** D26.9 : une clinique qui a des demandes avec un acompte payé ne se supprime pas ; une demande sans acompte payé ne la retient pas (26.10.2026). */
    public function test_une_clinique_aux_acomptes_payes_ne_se_supprime_pas(): void
    {
        $avecAcompte = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        DemandeRendezVous::factory()->create([
            'clinique_id' => $avecAcompte->id, 'acompte_centimes' => 3000, 'acompte_intention_id' => 'pi_x',
            'acompte_paye_le' => now(), 'acompte_rembourse_le' => now(), 'acompte_rembourse_centimes' => 3000, 'acompte_litige' => 'perdu',
        ]);

        $this->deleteJson("/api/v1/organisation/cliniques/{$avecAcompte->id}", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['clinique' => 'Cette clinique a des acomptes en ligne : elle se désactive, elle ne se supprime pas.']);
        $this->assertDatabaseHas('cliniques', ['id' => $avecAcompte->id]);

        // Un acompte en train d'être payé (intention ouverte) la retient aussi
        $enPaiement = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        app(ContexteClinique::class)->oublier();
        DemandeRendezVous::factory()->create(['clinique_id' => $enPaiement->id, 'acompte_centimes' => 3000, 'acompte_intention_id' => 'pi_y']);
        $this->deleteJson("/api/v1/organisation/cliniques/{$enPaiement->id}", [], $this->enTetes($this->jeton))->assertUnprocessable();

        $sansAcompte = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        app(ContexteClinique::class)->oublier();
        DemandeRendezVous::factory()->create(['clinique_id' => $sansAcompte->id]);
        $this->deleteJson("/api/v1/organisation/cliniques/{$sansAcompte->id}", [], $this->enTetes($this->jeton))->assertNoContent();
    }

    public function test_le_solde_de_conges_compte_aussi_les_conges_des_employes_des_autres_cliniques(): void
    {
        $autre = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $employe = Employe::factory()->dans($autre)->create(['conges_droit' => 25, 'conges_pris' => 5]);
        Absence::factory()->create([
            'employe_id' => $employe->id,
            'debut_le' => aujourdhui()->addDays(10),
            'fin_le' => aujourdhui()->addDays(14),
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

    public function test_un_renouvellement_que_la_banque_traite_encore_n_est_jamais_preleve_deux_fois(): void
    {
        $this->jamaisAbonnee();
        Mail::fake();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();

        // Échéance : la banque traite le prélèvement, l'heure suivante n'en refait pas
        $this->stripe->enCours = true;
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->voyager('2026-11-20 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertCount(2, $this->stripe->prelevements);
        $this->assertSame('2026-11-20', Organisation::query()->findOrFail($this->organisationId())->periode_fin->toDateString());

        // Passé (webhook manqué) : la période avance au passage suivant, sans autre prélèvement
        $this->stripe->confirmer($this->stripe->dernierPrelevement()['id']);
        $this->voyager('2026-11-20 08:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame(['actif', '2026-12-20'], [$organisation->statut_abonnement, $organisation->periode_fin->toDateString()]);
        $this->assertCount(2, $this->stripe->prelevements);

        // Refusé après coup : impayé et relance, comme un refus immédiat
        $this->voyager('2026-12-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->stripe->refuser($this->stripe->dernierPrelevement()['id']);
        $this->voyager('2026-12-20 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame('impaye', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);
        $this->assertCount(3, $this->stripe->prelevements);
    }

    /** Régler à la main pendant que la banque traite le renouvellement : jamais un second prélèvement. */
    public function test_regler_pendant_que_la_banque_traite_ne_preleve_pas_une_seconde_fois(): void
    {
        $this->jamaisAbonnee();
        Mail::fake();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();

        $this->stripe->enCours = true;
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertCount(2, $this->stripe->prelevements);
        $facture = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->where('statut', 'a_payer')->sole();

        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('facture');
        $this->assertCount(2, $this->stripe->prelevements);

        // Passé entre-temps (webhook manqué) : « Régler » constate le paiement, sans prélever
        $this->stripe->confirmer($this->stripe->dernierPrelevement()['id']);
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'payee');
        $this->assertCount(2, $this->stripe->prelevements);
    }

    /** Double clic sur « Régler » : le second attend le premier, un seul prélèvement (24.10.2026). */
    public function test_un_double_clic_sur_regler_ne_preleve_qu_une_fois(): void
    {
        $facture = $this->factureRefuseeARegler();
        $avant = count($this->stripe->prelevements);

        // Le premier clic tient le verrou de la facture
        $verrou = Cache::lock('encaisser:organisation:'.$facture->id, 120);
        $this->assertTrue($verrou->get());
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['facture' => 'Le paiement de cette facture est déjà en cours. Patientez un instant.']);
        $this->assertCount($avant, $this->stripe->prelevements);
        $verrou->release();

        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.statut', 'payee');
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->assertCount($avant + 1, $this->stripe->prelevements);
    }

    /** Une confirmation bancaire laissée en suspens est annulée avant un nouvel essai : le client ne peut pas payer deux fois. */
    public function test_regler_annule_la_confirmation_en_suspens_avant_un_nouvel_essai(): void
    {
        $facture = $this->factureRefuseeARegler();

        $this->stripe->actionRequise = true;
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))->assertOk();
        $idPremier = $this->stripe->dernierPrelevement()['id'];
        $this->assertSame('action_requise', $this->stripe->prelevements[$idPremier]['statut']);

        // Fenêtre de la banque fermée, « Régler » de nouveau
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))->assertOk();
        $idSecond = $this->stripe->dernierPrelevement()['id'];
        $this->assertNotSame($idPremier, $idSecond);
        $this->assertSame('annule', $this->stripe->prelevements[$idPremier]['statut']);

        // Le premier confirmé après coup ne prélève rien ; le second paie la facture
        $this->stripe->confirmer($idPremier);
        $this->stripe->confirmer($idSecond);
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/confirmer", [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.statut', 'payee');
        $this->assertSame('annule', $this->stripe->prelevements[$idPremier]['statut']);
    }

    /** Une réponse perdue puis renvoyée, même plus tard, garde la même clé ; après un refus, ou avec une autre carte, une autre. */
    public function test_la_cle_d_idempotence_suit_la_tentative(): void
    {
        $facture = $this->factureRefuseeARegler();
        $regler = fn () => $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton));

        $this->stripe->panne = 'Délai dépassé.';
        $regler()->assertUnprocessable();
        // Une heure et demie plus tard, toujours sans réponse : la même tentative
        $this->travel(90)->minutes();
        $regler()->assertUnprocessable();
        $this->stripe->panne = null;
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $regler()->assertUnprocessable();
        [$perdue, $renvoyee, $refusee] = array_slice($this->stripe->cles, -3);
        $this->assertSame($perdue, $renvoyee);
        $this->assertSame($renvoyee, $refusee);

        // Le refus a une réponse : l'essai suivant est une nouvelle tentative ; une autre carte aussi
        $this->stripe->refus = null;
        $this->avecCarte('4444');
        $regler()->assertOk();
        $this->assertNotSame($refusee, array_slice($this->stripe->cles, -1)[0]);
    }

    /** La clé change avec la carte seule : sans réponse, puis une autre carte, c'est une autre tentative (24.10.2026). */
    public function test_la_cle_d_idempotence_change_avec_la_carte(): void
    {
        $facture = $this->factureRefuseeARegler();
        $regler = fn () => $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton));

        $this->stripe->panne = 'Délai dépassé.';
        $regler()->assertUnprocessable();
        $sansReponse = array_slice($this->stripe->cles, -1)[0];
        $this->avecCarte('4444');
        // Tant que la demande perdue n'a pas de réponse, elle repart telle quelle (26.10.2026)
        $regler()->assertUnprocessable();
        $this->assertSame($sansReponse, array_slice($this->stripe->cles, -1)[0]);
        // Stripe répond par une erreur nette (400) : ni prélèvement ni tentative
        // comptée ; seule la carte change ensuite, et la clé avec elle
        $this->stripe->panne = null;
        $this->stripe->erreurServeur = 400;
        $regler()->assertUnprocessable();
        $repondue = array_slice($this->stripe->cles, -1)[0];
        $this->stripe->erreurServeur = null;
        $this->avecCarte('5555');
        $regler()->assertOk();
        $this->assertNotSame($repondue, array_slice($this->stripe->cles, -1)[0]);
    }

    /** La clé change avec la réponse seule : refusé, puis la même carte, c'est une autre tentative (24.10.2026). */
    public function test_la_cle_d_idempotence_change_apres_une_reponse(): void
    {
        $facture = $this->factureRefuseeARegler();
        $regler = fn () => $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton));

        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $regler()->assertUnprocessable();
        $refusee = array_slice($this->stripe->cles, -1)[0];
        $this->stripe->refus = null;
        $regler()->assertOk()->assertJsonPath('data.statut', 'payee');
        $this->assertNotSame($refusee, array_slice($this->stripe->cles, -1)[0]);
    }

    /** Une facture annulée ne s'applique jamais, même si son prélèvement passe après coup : signalée, à rendre (24.10.2026). */
    public function test_une_facture_annulee_ne_s_applique_jamais(): void
    {
        $this->jamaisAbonnee();
        $this->app->instance(PasserellePaiement::class, new PasserellePaiementFactice);
        $this->avecCarte();
        $this->stripe->actionRequise = true;
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $prelevement = $this->stripe->dernierPrelevement();
        $facture = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->sole();
        // Annulée pendant que la banque confirmait (le prélèvement n'a pas pu être arrêté)
        $facture->forceFill(['statut' => 'annulee'])->save();

        Log::spy();
        $this->postJson('/api/v1/stripe/webhook', ['id' => 'evt_annulee', 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => $prelevement['id'], 'status' => 'succeeded', 'amount' => $prelevement['centimes'], 'metadata' => $prelevement['metadonnees'],
        ]]], ['Stripe-Signature' => 'signature-valide'])->assertNoContent();

        $this->assertSame('annulee', $facture->refresh()->statut);
        $this->assertDatabaseMissing('organisations', ['id' => $this->organisationId(), 'statut_abonnement' => 'actif']);
        Log::shouldHaveReceived('critical')->withArgs(fn ($message) => str_contains($message, 'facture annulée'))->once();
    }

    /** Souscrire dans un onglet, la banque demande une confirmation ; souscrire de nouveau dans un autre : la première ne pourra plus passer (24.10.2026). */
    public function test_deux_souscriptions_en_attente_de_confirmation_ne_prelevent_qu_une_fois(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->stripe->actionRequise = true;

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $premier = $this->stripe->dernierPrelevement()['id'];
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $second = $this->stripe->dernierPrelevement()['id'];
        $this->assertNotSame($premier, $second);
        $this->assertSame('annule', $this->stripe->prelevements[$premier]['statut']);

        // Le premier onglet confirme après coup : rien ne passe ; le second paie
        $this->stripe->confirmer($premier);
        $this->stripe->confirmer($second);
        $factures = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement();
        $this->assertSame(1, (clone $factures)->where('statut', 'a_payer')->count());
        $aPayer = (clone $factures)->where('statut', 'a_payer')->sole();
        $this->postJson("/api/v1/organisation/factures/{$aPayer->id}/confirmer", [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.statut', 'payee');
        $this->assertSame(1, (clone $factures)->where('statut', 'payee')->count());
        $this->assertSame('annule', $this->stripe->prelevements[$premier]['statut']);
    }

    /** Un prélèvement refusé se confirme encore avec une autre carte : annulé avec sa facture, ou avant un nouvel essai (24.10.2026). */
    public function test_un_prelevement_refuse_est_annule_chez_stripe(): void
    {
        // Avant un nouvel essai
        $facture = $this->factureRefuseeARegler();
        $refuse = $facture->stripe_paiement_id;
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.statut', 'payee');
        $this->assertSame('annule', $this->stripe->prelevements[$refuse]['statut']);

        // Avec sa facture : un pack refusé
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $this->postJson('/api/v1/organisation/packs', ['type' => 'emails', 'quantite' => 5000], $this->enTetes($this->jeton))->assertUnprocessable();
        $pack = $this->stripe->dernierPrelevement()['id'];
        $this->assertSame('annule', $this->stripe->prelevements[$pack]['statut']);
        $this->stripe->refus = null;
        $this->stripe->confirmer($pack);
        $this->assertSame('annule', $this->stripe->prelevements[$pack]['statut']);
    }

    /** Deux onglets, ou un double clic : une souscription à la fois, une seule facture prélevée (24.10.2026). */
    public function test_une_souscription_deja_en_cours_n_en_lance_pas_une_seconde(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();

        // L'autre requête tient le verrou
        $verrou = Cache::lock('abonnement:organisation:'.$this->organisationId(), 120);
        $this->assertTrue($verrou->get());
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan' => 'Un paiement est déjà en cours. Patientez un instant.']);
        $this->assertSame([], $this->stripe->prelevements);
        $this->assertSame(0, Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->count());

        $verrou->release();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $this->assertCount(1, $this->stripe->prelevements);
    }

    /** Deux requêtes : la seconde, qui avait lu l'organisation avant la souscription de la première, la relit sous le verrou (24.10.2026). */
    public function test_une_souscription_relit_l_organisation_sous_le_verrou(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $luePlusTot = Organisation::query()->findOrFail($this->organisationId());

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();

        try {
            app(AbonnementSaas::class)->souscrire($luePlusTot, FormuleSaas::Cabinet);
            $this->fail('La seconde souscription aurait dû être refusée.');
        } catch (ValidationException $e) {
            $this->assertSame(['C’est déjà votre formule.'], $e->errors()['plan']);
        }
        $this->assertCount(1, $this->stripe->prelevements);
    }

    /** Changement de formule dans deux onglets, la banque demande une confirmation : le premier ne pourra plus passer (24.10.2026). */
    public function test_deux_changements_de_formule_en_attente_ne_prelevent_qu_une_fois(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $this->voyager('2026-10-25 10:00');
        $this->stripe->actionRequise = true;

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertOk();
        $premier = $this->stripe->dernierPrelevement()['id'];
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertOk();
        $second = $this->stripe->dernierPrelevement()['id'];

        $this->assertNotSame($premier, $second);
        $this->assertSame('annule', $this->stripe->prelevements[$premier]['statut']);
        $changements = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->where('statut', 'a_payer')->get()
            ->filter(fn ($f) => ($f->metadonnees['action'] ?? null) === 'changement');
        $this->assertCount(1, $changements);
    }

    /** Un changement de formule pendant qu'une autre requête tient le verrou de l'abonné : refusé, rien de prélevé (24.10.2026). */
    public function test_un_changement_de_formule_attend_la_souscription_en_cours(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $avant = count($this->stripe->prelevements);

        $verrou = Cache::lock('abonnement:organisation:'.$this->organisationId(), 120);
        $this->assertTrue($verrou->get());
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'Un paiement est déjà en cours. Patientez un instant.']);
        $this->assertCount($avant, $this->stripe->prelevements);
        $verrou->release();
    }

    /** Un prélèvement inconnu de la facture, dans une autre monnaie : pas le sien (24.10.2026). */
    public function test_un_prelevement_dans_une_autre_monnaie_ne_paie_pas_la_facture(): void
    {
        $this->jamaisAbonnee();
        $this->app->instance(PasserellePaiement::class, new PasserellePaiementFactice);
        $this->avecCarte();
        $this->stripe->actionRequise = true;
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $prelevement = $this->stripe->dernierPrelevement();
        $facture = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->sole();
        $facture->forceFill(['stripe_paiement_id' => null])->save();

        foreach (['eur' => 'evt_eur', null => 'evt_sans'] as $monnaie => $evt) {
            $this->postJson('/api/v1/stripe/webhook', ['id' => $evt, 'type' => 'payment_intent.succeeded', 'data' => ['object' => array_filter([
                'id' => $prelevement['id'], 'status' => 'succeeded', 'amount' => $prelevement['centimes'], 'currency' => $monnaie ?: null, 'metadata' => $prelevement['metadonnees'],
            ])]], ['Stripe-Signature' => 'signature-valide'])->assertNoContent();
            $this->assertSame('a_payer', $facture->refresh()->statut);
        }
    }

    /** Plus : une souscription à la fois, l'utilisateur relu sous le verrou (24.10.2026). */
    public function test_plus_relit_l_utilisateur_sous_le_verrou(): void
    {
        $user = User::factory()->create();
        $this->stripe->carteConfirmee('cus_plus');
        $user->forceFill(['stripe_client_id' => 'cus_plus', 'stripe_moyen_paiement_id' => array_key_last($this->stripe->cartes)])->save();
        $luPlusTot = User::query()->findOrFail($user->id);
        $saas = app(AbonnementSaas::class);

        $verrou = Cache::lock('abonnement:proprietaire:'.$user->id, 120);
        $this->assertTrue($verrou->get());
        try {
            $saas->souscrirePlus($luPlusTot);
            $this->fail('Une souscription est déjà en cours.');
        } catch (ValidationException $e) {
            $this->assertSame(['Un paiement est déjà en cours. Patientez un instant.'], $e->errors()['plan']);
        }
        $verrou->release();

        $saas->souscrirePlus(User::query()->findOrFail($user->id));
        try {
            $saas->souscrirePlus($luPlusTot);
            $this->fail('Plus est déjà souscrit.');
        } catch (ValidationException $e) {
            $this->assertSame(['Plus est déjà votre offre.'], $e->errors()['formule']);
        }
        $this->assertCount(1, $this->stripe->prelevements);
    }

    /** L'abonné n'est relu qu'une fois le verrou pris : refusé faute de verrou, rien n'est lu (24.10.2026). */
    public function test_l_abonne_n_est_relu_qu_une_fois_le_verrou_pris(): void
    {
        $this->jamaisAbonnee();
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $verrou = Cache::lock('abonnement:organisation:'.$organisation->id, 120);
        $this->assertTrue($verrou->get());
        $lectures = 0;
        DB::listen(function (QueryExecuted $requete) use (&$lectures): void {
            if (str_contains($requete->sql, 'from "organisations"')) {
                $lectures++;
            }
        });

        try {
            app(AbonnementSaas::class)->souscrire($organisation, FormuleSaas::Cabinet);
            $this->fail('Une souscription est déjà en cours.');
        } catch (ValidationException) {
        }
        $this->assertSame(0, $lectures);
        $verrou->release();
    }

    /** Le changement précédent a été réglé entre-temps (autre onglet) : le nouveau est refusé, rien n'est prélevé ; sinon il est annulé avant le nouveau (24.10.2026). */
    public function test_un_changement_deja_regle_bloque_le_suivant(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $this->voyager('2026-10-25 10:00');
        $this->stripe->actionRequise = true;

        // Deux onglets : le premier est annulé avant que le second ne prélève
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertOk();
        $premier = $this->stripe->dernierPrelevement()['id'];
        $this->stripe->journal = [];
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame(['annuler:'.$premier, 'prelever'], $this->stripe->journal);

        // Le second confirmé à la banque, sans webhook encore : un troisième est refusé
        $second = $this->stripe->dernierPrelevement()['id'];
        $this->stripe->confirmer($second);
        $avant = count($this->stripe->prelevements);
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan' => 'Un changement de formule précédent vient d’être réglé, est traité par votre banque ou reste à vérifier : rechargez la page avant de recommencer.']);
        $this->assertCount($avant, $this->stripe->prelevements);
    }

    /** Une souscription qui en remplace une autre (restée en attente de confirmation) garde les frais d'identité (24.10.2026). */
    public function test_la_souscription_qui_remplace_garde_les_frais_d_identite(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        Organisation::query()->whereKey($this->organisationId())->update(['frais_identite_centimes' => 150]);
        $this->stripe->actionRequise = true;

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $premier = $this->stripe->dernierPrelevement()['centimes'];
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame($premier, $this->stripe->dernierPrelevement()['centimes']);
        $souscription = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->where('statut', 'a_payer')->sole();
        $this->assertSame('150', $souscription->metadonnees['frais_identite'] ?? null);
    }

    /** Un changement qui en remplace un autre garde les frais d'identité que l'annulation a rendus (24.10.2026). */
    public function test_le_changement_qui_remplace_garde_les_frais_d_identite(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        Organisation::query()->whereKey($this->organisationId())->update(['frais_identite_centimes' => 150]);
        $this->voyager('2026-10-25 10:00');
        $this->stripe->actionRequise = true;

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertOk();
        $premier = $this->stripe->dernierPrelevement()['centimes'];
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame($premier, $this->stripe->dernierPrelevement()['centimes']);
        $changement = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->where('statut', 'a_payer')->sole();
        $this->assertSame('150', $changement->metadonnees['frais_identite'] ?? null);
        $this->assertNull(Organisation::query()->findOrFail($this->organisationId())->frais_identite_centimes);
    }

    /** Un prélèvement de la plateforme annoncé par le compte d'une société : ignoré (24.10.2026). */
    public function test_le_prelevement_de_la_plateforme_ne_vient_que_de_son_compte(): void
    {
        $this->jamaisAbonnee();
        $this->app->instance(PasserellePaiement::class, new PasserellePaiementFactice);
        $this->avecCarte();
        $this->stripe->actionRequise = true;
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $prelevement = $this->stripe->dernierPrelevement();
        $evenement = fn (string $id, array $plus) => ['id' => $id, 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => $prelevement['id'], 'status' => 'succeeded', 'amount' => $prelevement['centimes'], 'currency' => 'chf', 'metadata' => $prelevement['metadonnees'],
        ]], ...$plus];

        $this->postJson('/api/v1/stripe/webhook', $evenement('evt_societe', ['account' => 'acct_societe']), ['Stripe-Signature' => 'signature-valide'])->assertNoContent();
        $facture = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->sole();
        $this->assertSame('a_payer', $facture->statut);

        $this->postJson('/api/v1/stripe/webhook', $evenement('evt_plateforme', []), ['Stripe-Signature' => 'signature-valide'])->assertNoContent();
        $this->assertSame('payee', $facture->refresh()->statut);
    }

    /** L'essai de la nuit et « Régler » portent la même clé : une réponse perdue la nuit n'est pas prélevée deux fois (24.10.2026). */
    public function test_l_essai_de_la_nuit_et_regler_partagent_la_cle(): void
    {
        $this->jamaisAbonnee();
        Mail::fake();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();

        // La nuit du renouvellement, la réponse de Stripe se perd
        $this->stripe->panne = 'Délai dépassé.';
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $nuit = array_slice($this->stripe->cles, -1)[0];

        // Le matin, « Régler » avec la même carte
        $this->stripe->panne = null;
        $facture = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->where('statut', 'a_payer')->sole();
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame($nuit, array_slice($this->stripe->cles, -1)[0]);
        $this->assertFalse($this->stripe->dernierPrelevement()['hors_session']);
    }

    /** Réponse perdue (délai dépassé), prélèvement passé quand même : le webhook le reconnaît à sa facture et à son montant (24.10.2026). */
    public function test_le_webhook_d_un_prelevement_sans_reponse_paie_sa_facture(): void
    {
        $this->jamaisAbonnee();
        $this->app->instance(PasserellePaiement::class, new PasserellePaiementFactice);
        $this->avecCarte();
        $this->stripe->actionRequise = true;
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $prelevement = $this->stripe->dernierPrelevement();
        $facture = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->sole();
        // La facture n'a jamais reçu l'id : la réponse s'est perdue
        $facture->forceFill(['stripe_paiement_id' => null])->save();
        $webhook = fn (string $evt, array $objet) => $this->postJson('/api/v1/stripe/webhook', ['id' => $evt, 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'status' => 'succeeded', 'currency' => 'chf', 'metadata' => $prelevement['metadonnees'], ...$objet,
        ]]], ['Stripe-Signature' => 'signature-valide'])->assertNoContent();

        // Un autre montant : pas le sien
        $webhook('evt_autre', ['id' => 'pi_autre', 'amount' => $prelevement['centimes'] + 100]);
        $this->assertSame('a_payer', $facture->refresh()->statut);

        $webhook('evt_perdu', ['id' => $prelevement['id'], 'amount' => $prelevement['centimes']]);
        $facture->refresh();
        $this->assertSame('payee', $facture->statut);
        $this->assertSame($prelevement['id'], $facture->stripe_paiement_id);
        $this->assertDatabaseHas('organisations', ['id' => $this->organisationId(), 'statut_abonnement' => 'actif']);

        // Un second prélèvement réussi pour la même facture : signalé, à rendre
        Log::spy();
        $webhook('evt_double', ['id' => 'pi_double', 'amount' => $prelevement['centimes']]);
        $this->assertSame($prelevement['id'], $facture->refresh()->stripe_paiement_id);
        Log::shouldHaveReceived('critical')->withArgs(fn ($message) => str_contains($message, 'déjà payée'))->once();
    }

    /** D26.1a : après une erreur de Stripe qui a répondu (500), la tentative suivante part avec une nouvelle clé ; « Régler » ne rejoue pas la même erreur (26.10.2026). */
    public function test_apres_une_erreur_500_de_stripe_la_tentative_suivante_change_de_cle(): void
    {
        $facture = $this->factureRefuseeARegler();
        $regler = fn () => $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton));

        $this->stripe->erreurServeur = 500;
        $regler()->assertUnprocessable();
        $cleEnErreur = array_slice($this->stripe->cles, -1)[0];

        $this->stripe->erreurServeur = null;
        $regler()->assertOk()->assertJsonPath('data.statut', 'payee');
        $this->assertNotSame($cleEnErreur, array_slice($this->stripe->cles, -1)[0]);
    }

    /** D26.1b : une erreur sans réponse (délai, réseau) garde la même clé ; une erreur avec réponse la change (26.10.2026). */
    public function test_une_erreur_sans_reponse_garde_la_cle_une_erreur_500_la_change(): void
    {
        $facture = $this->factureRefuseeARegler();
        $regler = fn () => $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton));

        $this->stripe->panne = 'Délai dépassé.';
        $regler()->assertUnprocessable();
        $regler()->assertUnprocessable();
        [$premiere, $seconde] = array_slice($this->stripe->cles, -2);
        $this->assertSame($premiere, $seconde);

        $this->stripe->panne = null;
        $this->stripe->erreurServeur = 500;
        $regler()->assertUnprocessable();
        $this->stripe->erreurServeur = null;
        $regler()->assertOk();
        [$enErreur, $suivante] = array_slice($this->stripe->cles, -2);
        $this->assertSame($seconde, $enErreur);
        $this->assertNotSame($enErreur, $suivante);
    }

    /** D26.1b : un 409 de Stripe (la même clé déjà en cours) n'use pas la clé : le clic suivant la reprend (26.10.2026). */
    public function test_un_409_de_stripe_garde_la_cle(): void
    {
        $facture = $this->factureRefuseeARegler();
        $regler = fn () => $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton));

        $this->stripe->erreurServeur = 409;
        $regler()->assertUnprocessable();
        $this->stripe->erreurServeur = null;
        $regler()->assertOk();

        [$en409, $suivante] = array_slice($this->stripe->cles, -2);
        $this->assertSame($en409, $suivante);
    }

    /** D26.1c : une souscription dont le prélèvement passe malgré une erreur 500 n'est pas annulée : elle est payée, sans second achat (26.10.2026). */
    public function test_une_souscription_passee_malgre_une_erreur_500_est_payee(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->stripe->erreurServeur = 500;
        $this->stripe->passeMalgreErreur = true;

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();

        $factures = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->get();
        $this->assertCount(1, $factures);
        $this->assertSame('payee', $factures->sole()->statut);
        $this->assertCount(1, $this->stripe->prelevements);
        $this->assertDatabaseHas('organisations', ['id' => $this->organisationId(), 'statut_abonnement' => 'actif']);
    }

    /** Et un vrai échec (rien passé chez Stripe) annule la souscription comme avant (26.10.2026). */
    public function test_une_souscription_en_erreur_500_sans_prelevement_est_annulee(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->stripe->erreurServeur = 500;

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('paiement');

        $this->assertSame('annulee', Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->sole()->statut);
    }

    /** D26.1c : une erreur 500 alors que le prélèvement est passé chez Stripe : la tentative suivante le retrouve et le reprend, sans second prélèvement (26.10.2026). */
    public function test_un_prelevement_passe_malgre_une_erreur_500_est_repris(): void
    {
        $facture = $this->factureRefuseeARegler();
        $regler = fn () => $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton));

        $this->stripe->erreurServeur = 500;
        $this->stripe->passeMalgreErreur = true;
        $regler()->assertUnprocessable();
        $passe = $this->stripe->dernierPrelevement()['id'];
        $avant = count($this->stripe->prelevements);

        $this->stripe->erreurServeur = null;
        $this->stripe->passeMalgreErreur = false;
        $regler()->assertOk()->assertJsonPath('data.statut', 'payee');

        $this->assertCount($avant, $this->stripe->prelevements);
        $this->assertSame($passe, $facture->refresh()->stripe_paiement_id);
    }

    /** D26.2 : les routes d'abonnement de l'organisation ont leur propre compteur : une caisse qui a épuisé le sien ne les bloque pas, et inversement (26.10.2026). */
    public function test_l_abonnement_a_son_propre_compteur_de_frequence(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        // La caisse de la clinique a épuisé ses 180 appels à Stripe de la minute
        $cleCaisse = md5('stripe'.'stripe:'.$this->clinique->id);
        foreach (range(1, 180) as $appel) {
            RateLimiter::hit($cleCaisse, 60);
        }
        $this->postJson('/api/v1/facturation/terminaux', [], $this->enTetes($this->jeton))->assertStatus(429);

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();

        // Et l'inverse : le compteur de l'abonnement épuisé laisse la caisse tranquille
        RateLimiter::clear($cleCaisse);
        foreach (range(1, 30) as $appel) {
            RateLimiter::hit(md5('stripe-abonnement'.'stripe-abonnement:'.$this->user->id), 60);
        }
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $this->enTetes($this->jeton))->assertStatus(429);
        $this->postJson('/api/v1/facturation/terminaux', [], $this->enTetes($this->jeton))->assertStatus(422);
    }

    /** Une facture de renouvellement refusée, à régler, carte remplacée. */
    private function factureRefuseeARegler(): OrganisationFacture
    {
        $this->jamaisAbonnee();
        Mail::fake();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->stripe->refus = null;
        $this->avecCarte('5556');

        return Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->where('statut', 'a_payer')->sole();
    }

    public function test_l_acces_ne_survit_pas_a_une_echeance_jamais_renouvelee(): void
    {
        // Le planificateur arrêté : l'accès tient le délai de grâce, pas plus
        Organisation::query()->whereKey($this->organisationId())->update(['periode_fin' => now('Europe/Zurich')->subDays(Organisation::GRACE_JOURS)->toDateString()]);
        $this->assertTrue(Organisation::query()->findOrFail($this->organisationId())->abonnementEnCours());
        $this->assertTrue(Organisation::query()->ouvertes()->whereKey($this->organisationId())->exists());

        Organisation::query()->whereKey($this->organisationId())->update(['periode_fin' => now('Europe/Zurich')->subDays(Organisation::GRACE_JOURS + 1)->toDateString()]);
        $this->assertFalse(Organisation::query()->findOrFail($this->organisationId())->abonnementEnCours());
        $this->assertFalse(Organisation::query()->ouvertes()->whereKey($this->organisationId())->exists());

        // Résiliée : fermée dès l'échéance passée
        Organisation::query()->whereKey($this->organisationId())->update(['periode_fin' => now('Europe/Zurich')->subDay()->toDateString(), 'resilie_le' => now('Europe/Zurich')->subMonth()->toDateString()]);
        $this->assertFalse(Organisation::query()->findOrFail($this->organisationId())->abonnementEnCours());
        $this->assertFalse(Organisation::query()->ouvertes()->whereKey($this->organisationId())->exists());
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

        // Une erreur nette du prestataire (400) ne laisse pas de facture en attente
        $this->stripe->refus = null;
        $this->stripe->erreurServeur = 400;
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('paiement');
        $this->assertSame(0, OrganisationFacture::query()->where('statut', 'a_payer')->count());

        // Sans réponse (le prélèvement a pu passer) : la facture reste à vérifier
        // tant que Stripe ne répond pas, jamais refaite à l'aveugle (26.10.2026)
        $this->stripe->erreurServeur = null;
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement est en cours de vérification chez le prestataire : il se termine tout seul dans un instant.']);
        $this->assertSame(1, OrganisationFacture::query()->where('statut', 'a_payer')->count());
        // Stripe répond de nouveau : un second clic attend, la demande perdue est
        // renvoyée telle quelle et passe, un seul prélèvement en tout (26.10.2026)
        $this->stripe->panne = null;
        $avant = count($this->stripe->prelevements);
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'Une souscription précédente est encore traitée par votre banque ou en vérification chez le prestataire. Réessayez dans quelques minutes.']);
        $this->assertCount($avant, $this->stripe->prelevements);
        $this->travel(1)->minutes();
        $this->artisan('abonnements:verifier-paiements')->assertSuccessful();
        $this->assertCount($avant + 1, $this->stripe->prelevements);
        $this->assertSame(0, OrganisationFacture::query()->where('statut', 'a_payer')->count());
        $this->assertSame(1, OrganisationFacture::query()->where('statut', 'payee')->count());
        $this->assertDatabaseHas('organisations', ['id' => $this->organisationId(), 'plan' => 'hopital', 'statut_abonnement' => 'actif']);
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

    /** Les deux premières relances refusées, jusqu'au matin de la dernière (23.11.2026). */
    private function jusquALaDerniereRelance(): void
    {
        $this->abonneeAvecRenouvellementRefuse();
        foreach (['2026-11-21 07:00', '2026-11-22 07:00'] as $quand) {
            $this->travelTo(CarbonImmutable::parse($quand, 'Europe/Zurich'));
            $this->artisan('abonnements:renouveler')->assertSuccessful();
        }
        $this->stripe->refus = null;
    }

    /** D26.1c : dernière relance en erreur 500, Stripe injoignable pour vérifier : l'accès ne se ferme pas, aucun e-mail « accès fermé », on revient une heure plus tard (26.10.2026). */
    public function test_une_derniere_relance_inverifiable_ne_ferme_rien_et_n_ecrit_pas(): void
    {
        $this->jusquALaDerniereRelance();
        $fermetures = fn () => count(Mail::sent(PaiementAbonnementRefuse::class, fn ($mail) => $mail->prochaineTentative === null));
        $avant = $fermetures();
        $this->stripe->erreurServeur = 500;
        $this->stripe->listeEnPanne = true;

        $this->voyager('2026-11-23 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->voyager('2026-11-23 08:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame('impaye', $organisation->statut_abonnement);
        $this->assertSame($avant, $fermetures());
        $this->assertSame('2026-11-23 09:00', $organisation->prochaine_tentative_le->timezone('Europe/Zurich')->format('Y-m-d H:i'));

        // Stripe répond de nouveau, la carte est refusée : l'accès se ferme, un e-mail
        $this->stripe->erreurServeur = null;
        $this->stripe->listeEnPanne = false;
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $this->voyager('2026-11-23 09:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame('resilie', $organisation->refresh()->statut_abonnement);
        $this->assertSame($avant + 1, $fermetures());
    }

    /** Dernière relance refusée, mais la tentative précédente restée sans réponse ne se vérifie pas : rien ne se ferme, nouvel essai six heures plus tard, à l'heure pleine (26.10.2026). */
    public function test_une_derniere_relance_refusee_mais_inverifiable_revient_six_heures_plus_tard(): void
    {
        $this->jusquALaDerniereRelance();
        $fermetures = fn () => count(Mail::sent(PaiementAbonnementRefuse::class, fn ($mail) => $mail->prochaineTentative === null));
        $avant = $fermetures();
        $this->stripe->erreurServeur = 500;
        $this->voyager('2026-11-23 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        // Une heure plus tard, la carte est refusée, et la liste tombe juste avant de clore
        $this->stripe->erreurServeur = null;
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $this->stripe->listeRepondEncore = 1;
        $this->travelTo(CarbonImmutable::parse('2026-11-23 08:00:03', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame('impaye', $organisation->statut_abonnement);
        $this->assertSame($avant, $fermetures());
        $this->assertSame('2026-11-23 14:00:00', $organisation->prochaine_tentative_le->timezone('Europe/Zurich')->format('Y-m-d H:i:s'));

        // La liste revient : refusée de nouveau, l'accès se ferme, un e-mail
        $this->stripe->listeRepondEncore = null;
        $this->stripe->listeEnPanne = false;
        $this->travelTo(CarbonImmutable::parse('2026-11-23 14:00:01', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame('resilie', $organisation->refresh()->statut_abonnement);
        $this->assertSame($avant + 1, $fermetures());
    }

    /** D26.1c : dernière relance passée malgré une erreur 500 : payée, l'abonnement continue, aucun e-mail « accès fermé » (26.10.2026). */
    public function test_une_derniere_relance_passee_malgre_une_erreur_500_n_ecrit_pas(): void
    {
        $this->jusquALaDerniereRelance();
        $fermetures = fn () => count(Mail::sent(PaiementAbonnementRefuse::class, fn ($mail) => $mail->prochaineTentative === null));
        $avant = $fermetures();
        $this->stripe->erreurServeur = 500;
        $this->stripe->passeMalgreErreur = true;

        $this->voyager('2026-11-23 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $this->assertSame('actif', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);
        $this->assertSame($avant, $fermetures());
    }

    /** D26.1c : un pack passé malgré une erreur 500, Stripe injoignable pour vérifier : aucun second pack ne se paie avant de savoir (26.10.2026). */
    public function test_un_pack_en_doute_n_est_jamais_paye_deux_fois(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $avant = count($this->stripe->prelevements);
        $acheter = fn () => $this->postJson('/api/v1/organisation/packs', ['type' => 'emails', 'quantite' => 5000], $this->enTetes($this->jeton));

        $this->stripe->erreurServeur = 500;
        $this->stripe->passeMalgreErreur = true;
        $this->stripe->listeEnPanne = true;
        $acheter()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement est en cours de vérification chez le prestataire : il se termine tout seul dans un instant.']);

        // Nouveau clic, Stripe toujours injoignable : rien de prélevé
        $this->stripe->erreurServeur = null;
        $this->stripe->passeMalgreErreur = false;
        $acheter()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement précédent est encore en vérification chez le prestataire. Réessayez dans une minute.']);
        $this->assertCount($avant + 1, $this->stripe->prelevements);

        // Stripe répond : le premier était passé, il est réglé, rien de plus
        $this->stripe->listeEnPanne = false;
        $acheter()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Votre achat précédent vient d’être confirmé : il est réglé.']);
        $this->assertCount($avant + 1, $this->stripe->prelevements);
        $packs = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->get()->filter(fn ($f) => ($f->metadonnees['type'] ?? null) === 'pack');
        $this->assertSame(['payee'], $packs->pluck('statut')->unique()->values()->all());
        $this->assertCount(1, $packs);
    }

    /** D26.1c : « Régler » après une erreur 500, Stripe injoignable pour vérifier : rien n'est prélevé (26.10.2026). */
    public function test_regler_sans_pouvoir_verifier_ne_preleve_rien(): void
    {
        $facture = $this->factureRefuseeARegler();
        $regler = fn () => $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton));
        $this->stripe->erreurServeur = 500;
        $regler()->assertUnprocessable();
        $avant = count($this->stripe->prelevements);

        $this->stripe->erreurServeur = null;
        $this->stripe->listeEnPanne = true;
        $regler()->assertUnprocessable();

        $this->assertCount($avant, $this->stripe->prelevements);
        $this->assertSame('a_payer', $facture->refresh()->statut);
    }

    /** D26.1c : un pack passé alors que la réponse s'est perdue (délai) : retrouvé dans la liste du client, il est payé, jamais annulé ni refait (26.10.2026). */
    public function test_un_pack_a_la_reponse_perdue_est_reconnu_paye(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $avant = count($this->stripe->prelevements);

        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;
        $this->postJson('/api/v1/organisation/packs', ['type' => 'emails', 'quantite' => 5000], $this->enTetes($this->jeton))->assertOk();

        $this->assertCount($avant + 1, $this->stripe->prelevements);
        $packs = Organisation::query()->findOrFail($this->organisationId())->facturesAbonnement()->get()->filter(fn ($f) => ($f->metadonnees['type'] ?? null) === 'pack');
        $this->assertSame(['payee'], $packs->pluck('statut')->values()->all());
    }

    /** D26.1c : dernière relance passée alors que la réponse s'est perdue : payée, l'abonnement continue, aucun e-mail « accès fermé » (26.10.2026). */
    public function test_une_derniere_relance_a_la_reponse_perdue_passee_ne_ferme_rien(): void
    {
        $this->jusquALaDerniereRelance();
        $fermetures = fn () => count(Mail::sent(PaiementAbonnementRefuse::class, fn ($mail) => $mail->prochaineTentative === null));
        $avant = $fermetures();
        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;

        $this->voyager('2026-11-23 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $this->assertSame('actif', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);
        $this->assertSame($avant, $fermetures());
    }

    /** Une panne de Stripe au renouvellement n'est pas un refus de la carte : ni relance consommée, ni e-mail « paiement refusé », ni impayé ; nouvel essai à l'heure suivante, sur la même facture (26.10.2026). */
    public function test_une_panne_de_stripe_au_renouvellement_n_est_pas_un_refus(): void
    {
        $this->jamaisAbonnee();
        Mail::fake();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $this->stripe->erreurServeur = 500;

        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        // Rien n'a été refusé : l'abonnement reste actif (pas d'« impayé » affiché),
        // l'échéance passée le ramène à la commande de l'heure suivante
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame('actif', $organisation->statut_abonnement);
        $this->assertSame(0, (int) $organisation->tentatives);
        Mail::assertNotSent(PaiementAbonnementRefuse::class);

        // Stripe répond de nouveau une heure plus tard : payé, sur la même facture
        $this->stripe->erreurServeur = null;
        $this->voyager('2026-11-20 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame('actif', $organisation->refresh()->statut_abonnement);
        $this->assertTrue($organisation->periode_fin->gt(CarbonImmutable::parse('2026-12-01')));
        $renouvellements = $organisation->facturesAbonnement()->get()->filter(fn ($f) => ($f->metadonnees['action'] ?? null) === 'renouvellement');
        $this->assertSame(['payee'], $renouvellements->pluck('statut')->values()->all());
    }

    /** Une erreur nette de Stripe au renouvellement (400, client inconnu) est un refus : relance comptée, e-mail, jamais des essais sans fin (26.10.2026). */
    public function test_une_erreur_nette_au_renouvellement_est_un_refus(): void
    {
        $this->jamaisAbonnee();
        Mail::fake();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $this->stripe->erreurServeur = 400;

        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame('impaye', $organisation->statut_abonnement);
        $this->assertSame(1, (int) $organisation->tentatives);
        Mail::assertSent(PaiementAbonnementRefuse::class, 1);
    }

    /** Une panne pendant les relances : l'essai suivant tombe à l'heure pleine, que la commande trouve à son passage (26.10.2026). */
    public function test_l_essai_apres_une_panne_tombe_a_l_heure_pleine(): void
    {
        $this->abonneeAvecRenouvellementRefuse();
        $this->stripe->refus = null;
        $this->stripe->erreurServeur = 500;
        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:00:03', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame('2026-11-21 08:00:00', $organisation->prochaine_tentative_le->timezone('Europe/Zurich')->format('Y-m-d H:i:s'));

        $this->stripe->erreurServeur = null;
        $this->travelTo(CarbonImmutable::parse('2026-11-21 08:00:01', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame('actif', $organisation->refresh()->statut_abonnement);
    }

    /** Pack : réponse perdue (prélèvement passé) et liste de Stripe en panne, puis liste revenue mais pas encore à jour : jamais un second prélèvement (26.10.2026). */
    public function test_un_pack_a_la_reponse_perdue_et_la_liste_en_panne_n_est_pas_preleve_deux_fois(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $avant = count($this->stripe->prelevements);
        $acheter = fn () => $this->postJson('/api/v1/organisation/packs', ['type' => 'emails', 'quantite' => 5000], $this->enTetes($this->jeton));

        // Prélevé, mais la réponse se perd et la liste ne répond pas : rien n'est conclu
        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;
        $this->stripe->listeEnPanne = true;
        $acheter()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement est en cours de vérification chez le prestataire : il se termine tout seul dans un instant.']);
        $this->assertCount($avant + 1, $this->stripe->prelevements);

        // Nouveau clic, Stripe répond de nouveau mais la liste toujours pas : en vérification, rien de prélevé
        $this->stripe->panne = null;
        $this->stripe->passeMalgreErreur = false;
        $acheter()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement précédent est encore en vérification chez le prestataire. Réessayez dans une minute.']);
        $this->assertCount($avant + 1, $this->stripe->prelevements);

        // La liste revient sans montrer encore le prélèvement (Stripe le traite) : toujours rien
        $this->stripe->listeEnPanne = false;
        $this->stripe->listeEnRetard = true;
        $acheter()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement précédent est encore en vérification chez le prestataire. Réessayez dans une minute.']);
        $this->assertCount($avant + 1, $this->stripe->prelevements);

        // Il paraît : repris, le pack est réglé
        $this->stripe->listeEnRetard = false;
        $acheter()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Votre achat précédent vient d’être confirmé : il est réglé.']);
        $this->assertCount($avant + 1, $this->stripe->prelevements);
        $packs = OrganisationFacture::query()->get()->filter(fn (OrganisationFacture $f) => ($f->metadonnees['type'] ?? null) === 'pack');
        $this->assertSame(['payee'], $packs->pluck('statut')->all());
    }

    /** Deux clics au même instant sur un pack : le second attend que le premier finisse (26.10.2026). */
    public function test_un_pack_deja_en_cours_n_en_lance_pas_un_second(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
        $avant = count($this->stripe->prelevements);

        $verrou = Cache::lock('abonnement:organisation:'.$this->organisationId(), 120);
        $this->assertTrue($verrou->get());
        $this->postJson('/api/v1/organisation/packs', ['type' => 'emails', 'quantite' => 5000], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Un paiement est déjà en cours. Patientez un instant.']);
        $this->assertCount($avant, $this->stripe->prelevements);
        $verrou->release();
    }

    /** Abonnée à Cabinet le 20.10.2026, carte enregistrée, e-mails retenus. */
    private function abonneeACabinet(): void
    {
        $this->jamaisAbonnee();
        Mail::fake();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertOk();
    }

    /** @return list<array<string, mixed>> */
    private function prelevementsDepuis(int $avant): array
    {
        return array_slice(array_values($this->stripe->prelevements), $avant);
    }

    /** Un changement de formule resté sans réponse puis abandonné : le renouvellement l'annule sans jamais le prélever, et ne prélève que la formule en cours (26.10.2026). */
    public function test_un_changement_abandonne_n_est_jamais_preleve_au_renouvellement(): void
    {
        $this->abonneeACabinet();
        $this->voyager('2026-11-10 10:00');
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;
        $avant = count($this->stripe->prelevements);

        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $nouveaux = $this->prelevementsDepuis($avant);
        $this->assertCount(1, $nouveaux);
        $this->assertSame(FormuleSaas::Cabinet->prixMensuel() * 100, $nouveaux[0]['centimes']);
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame([FormuleSaas::Cabinet, 'actif', '2026-12-20'], [$organisation->plan, $organisation->statut_abonnement, $organisation->periode_fin->toDateString()]);
        $changement = OrganisationFacture::query()->get()->first(fn (OrganisationFacture $f) => ($f->metadonnees['action'] ?? null) === 'changement');
        $this->assertSame('annulee', $changement->statut);
    }

    /** Le même changement abandonné, puis une résiliation : rien n'est prélevé, l'abonnement se termine (26.10.2026). */
    public function test_un_changement_abandonne_puis_une_resiliation_ne_prelevent_rien(): void
    {
        $this->abonneeACabinet();
        $this->voyager('2026-11-10 10:00');
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;
        $this->voyager('2026-11-12 10:00');
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $this->enTetes($this->jeton))->assertOk();
        $avant = count($this->stripe->prelevements);

        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $this->assertSame([], $this->prelevementsDepuis($avant));
        $this->assertSame('resilie', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);
    }

    /** Une souscription restée sans réponse, puis une autre formule : la seconde attend ; la première, renvoyée telle quelle, passe une fois (26.10.2026). */
    public function test_une_souscription_sans_reponse_puis_une_autre_formule_attend_la_premiere(): void
    {
        $this->jamaisAbonnee();
        $this->voyager('2026-10-20 10:00');
        $this->avecCarte();
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;
        $avant = count($this->stripe->prelevements);

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'individuel'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'Une souscription précédente est encore traitée par votre banque ou en vérification chez le prestataire. Réessayez dans quelques minutes.']);
        $this->assertSame([], $this->prelevementsDepuis($avant));

        $this->travel(1)->minutes();
        $this->artisan('abonnements:verifier-paiements')->assertSuccessful();
        $nouveaux = $this->prelevementsDepuis($avant);
        $this->assertCount(1, $nouveaux);
        $this->assertSame(FormuleSaas::Hopital->prixMensuel() * 100, $nouveaux[0]['centimes']);
        $this->assertDatabaseHas('organisations', ['id' => $this->organisationId(), 'plan' => 'hopital', 'statut_abonnement' => 'actif']);
    }

    /** Réponse perdue au renouvellement, prélèvement encore en traitement chez Stripe, puis nouvelle carte et « Régler » : rien sur la nouvelle carte tant que le premier peut passer (26.10.2026). */
    public function test_une_nouvelle_carte_ne_preleve_pas_pendant_qu_une_demande_perdue_peut_passer(): void
    {
        $this->abonneeACabinet();
        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;
        $this->stripe->listeEnRetard = true;
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->stripe->panne = null;
        $this->stripe->passeMalgreErreur = false;
        $avant = count($this->stripe->prelevements);
        $facture = OrganisationFacture::query()->where('statut', 'a_payer')->sole();

        $this->voyager('2026-11-20 06:02');
        $this->avecCarte('5555');
        // La demande de la nuit, renvoyée telle quelle (même carte, même clé), rend le premier prélèvement
        $this->postJson('/api/v1/organisation/factures/'.$facture->id.'/regler', [], $this->enTetes($this->jeton))->assertOk();
        $this->assertSame([], $this->prelevementsDepuis($avant));
        $this->assertSame('payee', $facture->refresh()->statut);
    }

    /** La clé Stripe de la plateforme refusée (401) au renouvellement : pas un refus de carte, ni relance ni e-mail à l'abonné ; la plateforme est alertée une fois par jour (26.10.2026). */
    public function test_une_cle_stripe_refusee_au_renouvellement_n_est_pas_un_refus_de_carte(): void
    {
        config(['plateforme.alerte_email' => 'alertes@meteora.test']);
        $this->abonneeACabinet();
        Log::spy();
        $this->stripe->erreurServeur = 401;
        foreach (['2026-11-20 06:00', '2026-11-20 07:00'] as $quand) {
            $this->voyager($quand);
            $this->artisan('abonnements:renouveler')->assertSuccessful();
        }

        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame(['actif', 0], [$organisation->statut_abonnement, (int) $organisation->tentatives]);
        Mail::assertNotSent(PaiementAbonnementRefuse::class);
        Mail::assertSent(CleStripeRefusee::class, 1);
        Log::shouldHaveReceived('critical')->with('Stripe refuse la clé de la plateforme.', \Mockery::any())->atLeast()->once();

        // Le lendemain, toujours refusée : une nouvelle alerte
        $this->voyager('2026-11-21 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        Mail::assertSent(CleStripeRefusee::class, 2);

        $this->stripe->erreurServeur = null;
        $this->voyager('2026-11-21 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame('2026-12-20', $organisation->refresh()->periode_fin->toDateString());
    }

    /** Un changement resté sans réponse juste avant l'échéance : le renouvellement attend ; renvoyé tel quel, le changement passe et ouvre sa période, rien d'autre n'est prélevé (26.10.2026). */
    public function test_un_changement_encore_a_verifier_retarde_le_renouvellement(): void
    {
        $this->abonneeACabinet();
        $this->voyager('2026-11-20 05:55');
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;
        $avant = count($this->stripe->prelevements);

        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame([], $this->prelevementsDepuis($avant));
        $this->assertSame('2026-11-20', Organisation::query()->findOrFail($this->organisationId())->periode_fin->toDateString());

        $this->artisan('abonnements:verifier-paiements')->assertSuccessful();
        $this->voyager('2026-11-20 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertCount(1, $this->prelevementsDepuis($avant));
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame([FormuleSaas::Hopital, '2026-12-20'], [$organisation->plan, $organisation->periode_fin->toDateString()]);
    }

    /** Un changement passé malgré une réponse perdue, retrouvé chez Stripe au renouvellement : il ouvre sa période, rien d'autre n'est prélevé (26.10.2026). */
    public function test_un_changement_passe_retrouve_au_renouvellement_ne_preleve_pas_la_periode(): void
    {
        $this->abonneeACabinet();
        $this->voyager('2026-11-10 10:00');
        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;
        $this->stripe->listeEnPanne = true;
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;
        $this->stripe->passeMalgreErreur = false;
        $this->stripe->listeEnPanne = false;
        $avant = count($this->stripe->prelevements);

        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $this->assertSame([], $this->prelevementsDepuis($avant));
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame([FormuleSaas::Hopital, 'actif', '2026-12-10'], [$organisation->plan, $organisation->statut_abonnement, $organisation->periode_fin->toDateString()]);
    }

    /** Une réponse perdue, puis une erreur 500 sur la même demande : Stripe a répondu, plus rien n'est en suspens, « Régler » repart aussitôt avec une nouvelle clé (26.10.2026). */
    public function test_une_erreur_500_apres_une_reponse_perdue_ne_bloque_pas_regler(): void
    {
        $facture = $this->factureRefuseeARegler();
        $regler = fn () => $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton));
        $this->stripe->panne = 'Délai dépassé.';
        $regler()->assertUnprocessable();
        $this->stripe->panne = null;
        $this->stripe->erreurServeur = 500;
        $regler()->assertUnprocessable();
        $this->stripe->erreurServeur = null;

        $regler()->assertOk()->assertJsonPath('data.statut', 'payee');
    }

    /** Une alerte de clé Stripe qui ne part pas ne fait rien échouer : « Régler » répond le message habituel, le renouvellement continue, l'abonné garde son accès (26.10.2026). */
    public function test_une_alerte_de_cle_stripe_qui_ne_part_pas_ne_fait_rien_echouer(): void
    {
        config(['plateforme.alerte_email' => 'alertes@meteora.test']);
        $facture = $this->factureRefuseeARegler();
        $this->stripe->erreurServeur = 403;
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP indisponible'));

        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('paiement');
        $this->voyager('2026-11-21 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame(['impaye', 1], [$organisation->statut_abonnement, (int) $organisation->tentatives]);
    }

    /** Une panne du cache n'empêche ni « Régler » ni le renouvellement : l'alerte est sautée, signalée au journal (26.10.2026). */
    public function test_une_alerte_de_cle_stripe_sans_cache_ne_fait_rien_echouer(): void
    {
        config(['plateforme.alerte_email' => 'alertes@meteora.test']);
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('Cache indisponible'));
        Mail::fake();

        app(AlerteCleStripe::class)->signaler(401);

        Mail::assertNothingSent();
    }

    /** Une alerte de clé Stripe qui n'a pas pu partir libère la place du jour : le refus suivant réessaie (26.10.2026). */
    public function test_une_alerte_de_cle_stripe_pas_partie_repart_au_refus_suivant(): void
    {
        config(['plateforme.alerte_email' => 'alertes@meteora.test']);
        $envoi = \Mockery::mock();
        $envoi->shouldReceive('send')->once();
        $appels = 0;
        Mail::shouldReceive('to')->twice()->andReturnUsing(function () use (&$appels, $envoi) {
            if (++$appels === 1) {
                throw new \RuntimeException('SMTP indisponible');
            }

            return $envoi;
        });

        app(AlerteCleStripe::class)->signaler(401);
        app(AlerteCleStripe::class)->signaler(401);
        app(AlerteCleStripe::class)->signaler(401);
    }

    /** La clé refusée aussi en cherchant le prélèvement d'une facture à vérifier : la plateforme est alertée, l'abonné n'est pas refusé (26.10.2026). */
    public function test_une_cle_stripe_refusee_en_verifiant_alerte_aussi(): void
    {
        config(['plateforme.alerte_email' => 'alertes@meteora.test']);
        $this->abonneeACabinet();
        $this->stripe->erreurServeur = 500;
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->stripe->erreurServeur = null;
        $this->stripe->cleRefusee = true;

        $this->voyager('2026-11-20 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        Mail::assertSent(CleStripeRefusee::class, 1);
        Mail::assertNotSent(PaiementAbonnementRefuse::class);
        $this->assertSame('actif', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);
    }

    /** La clé refusée en cherchant le prélèvement d'une facture à vérifier, depuis « Régler » : la plateforme est alertée (26.10.2026). */
    public function test_une_cle_stripe_refusee_en_verifiant_depuis_regler_alerte_aussi(): void
    {
        config(['plateforme.alerte_email' => 'alertes@meteora.test']);
        $this->abonneeACabinet();
        $this->stripe->erreurServeur = 500;
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->stripe->erreurServeur = null;
        $this->stripe->cleRefusee = true;
        $facture = OrganisationFacture::query()->where('statut', 'a_payer')->sole();

        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))->assertUnprocessable();

        Mail::assertSent(CleStripeRefusee::class, 1);
    }

    /** La clé refusée en vérifiant un achat précédent resté en doute : la plateforme est alertée, le nouvel achat attend (26.10.2026). */
    public function test_une_cle_stripe_refusee_en_verifiant_un_achat_alerte_aussi(): void
    {
        config(['plateforme.alerte_email' => 'alertes@meteora.test']);
        $this->abonneeACabinet();
        $acheter = fn () => $this->postJson('/api/v1/organisation/packs', ['type' => 'emails', 'quantite' => 5000], $this->enTetes($this->jeton));
        $this->stripe->erreurServeur = 500;
        $this->stripe->listeEnPanne = true;
        $acheter()->assertUnprocessable();
        $this->stripe->erreurServeur = null;
        $this->stripe->listeEnPanne = false;
        $this->stripe->cleRefusee = true;

        $acheter()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement précédent est encore en vérification chez le prestataire. Réessayez dans une minute.']);
        Mail::assertSent(CleStripeRefusee::class, 1);
    }

    /** Deux onglets : une résiliation pendant un paiement en cours attend qu'il finisse (26.10.2026). */
    public function test_une_resiliation_pendant_un_paiement_en_cours_attend(): void
    {
        $this->abonneeACabinet();
        $verrou = Cache::lock('abonnement:organisation:'.$this->organisationId(), 120);
        $this->assertTrue($verrou->get());

        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['abonnement' => 'Un paiement est déjà en cours. Patientez un instant.']);
        $verrou->release();
        $this->assertNull(Organisation::query()->findOrFail($this->organisationId())->resilie_le);
    }

    /** La clé refusée en relisant le prélèvement d'une relance : la plateforme est alertée, aucune relance n'est comptée (26.10.2026). */
    public function test_une_cle_stripe_refusee_en_relisant_une_relance_alerte_aussi(): void
    {
        config(['plateforme.alerte_email' => 'alertes@meteora.test']);
        $this->abonneeAvecRenouvellementRefuse();
        $this->stripe->cleRefusee = true;

        $this->voyager('2026-11-21 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        Mail::assertSent(CleStripeRefusee::class, 1);
        $this->assertSame(1, (int) Organisation::query()->findOrFail($this->organisationId())->tentatives);
    }

    /** Une demande perdue retrouvée chez Stripe en attente de 3-D Secure : elle n'est plus en suspens, « Régler » la remplace aussitôt (26.10.2026). */
    public function test_une_demande_perdue_retrouvee_en_attente_ne_bloque_pas_regler(): void
    {
        $facture = $this->factureRefuseeARegler();
        $regler = fn () => $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton));
        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;
        $this->stripe->actionRequise = true;
        $regler()->assertUnprocessable();
        $this->stripe->panne = null;
        $this->stripe->passeMalgreErreur = false;
        $this->stripe->actionRequise = false;

        $regler()->assertOk()->assertJsonPath('data.statut', 'payee');
    }

    /** Un 409 sur un pack (la même demande encore traitée chez Stripe) : un nouveau pack attend de savoir, rien n'est prélevé (26.10.2026). */
    public function test_un_409_sur_un_pack_met_la_demande_en_suspens(): void
    {
        $this->abonneeACabinet();
        $acheter = fn () => $this->postJson('/api/v1/organisation/packs', ['type' => 'emails', 'quantite' => 5000], $this->enTetes($this->jeton));
        $this->stripe->erreurServeur = 409;
        $acheter()->assertUnprocessable();
        $this->stripe->erreurServeur = null;
        $avant = count($this->stripe->prelevements);

        $acheter()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement précédent est encore en vérification chez le prestataire. Réessayez dans une minute.']);
        $this->assertSame([], $this->prelevementsDepuis($avant));
    }

    /** Un changement de formule encore à vérifier : un second changement est refusé avec « réessayez dans quelques minutes » (26.10.2026). */
    public function test_un_changement_encore_a_verifier_dit_de_reessayer_dans_quelques_minutes(): void
    {
        $this->abonneeACabinet();
        $this->voyager('2026-11-10 10:00');
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'Un changement de formule précédent est encore traité par votre banque ou en vérification chez le prestataire. Réessayez dans quelques minutes.']);
    }

    /** Un changement passé malgré une réponse perdue, puis une résiliation : le changement s'applique d'abord, la résiliation court jusqu'à sa nouvelle échéance, rien d'autre n'est prélevé (26.10.2026). */
    public function test_une_resiliation_apres_un_changement_passe_le_garde_et_ne_preleve_plus(): void
    {
        $this->abonneeACabinet();
        $this->voyager('2026-11-10 10:00');
        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;
        $this->stripe->listeEnPanne = true;
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;
        $this->stripe->passeMalgreErreur = false;
        $this->stripe->listeEnPanne = false;
        $avant = count($this->stripe->prelevements);

        $this->voyager('2026-11-12 10:00');
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $this->enTetes($this->jeton))->assertOk();
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame([FormuleSaas::Hopital, '2026-12-10', '2026-12-10'], [$organisation->plan, $organisation->periode_fin->toDateString(), $organisation->resilie_le?->toDateString()]);

        foreach (['2026-11-20 06:00', '2026-12-10 06:00'] as $quand) {
            $this->voyager($quand);
            $this->artisan('abonnements:renouveler')->assertSuccessful();
        }
        $this->assertSame([], $this->prelevementsDepuis($avant));
        $this->assertSame('resilie', $organisation->refresh()->statut_abonnement);
    }

    /** Un changement encore à vérifier : la résiliation attend de savoir (26.10.2026). */
    public function test_une_resiliation_attend_un_changement_encore_a_verifier(): void
    {
        $this->abonneeACabinet();
        $this->voyager('2026-11-10 10:00');
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;

        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['abonnement' => 'Un changement de formule est encore en vérification chez le prestataire. Réessayez dans quelques minutes.']);
        $this->assertNull(Organisation::query()->findOrFail($this->organisationId())->resilie_le);
    }

    /** Résiliée pendant les relances d'un renouvellement refusé : la relance suivante ne prélève rien, l'abonnement se termine (26.10.2026). */
    public function test_une_resiliation_pendant_les_relances_n_est_pas_prelevee(): void
    {
        $this->abonneeAvecRenouvellementRefuse();
        $this->stripe->refus = null;
        $this->voyager('2026-11-20 09:00');
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $this->enTetes($this->jeton))->assertOk();
        $avant = count($this->stripe->prelevements);

        $this->voyager('2026-11-21 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $this->assertSame([], $this->prelevementsDepuis($avant));
        $this->assertSame('resilie', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);
        $this->assertSame(0, OrganisationFacture::query()->where('statut', 'a_payer')->count());
    }

    /** L'e-mail de la clé refusée vaut pour tout prélèvement de la plateforme, et ne promet la fermeture qu'aux organisations (26.10.2026). */
    public function test_l_alerte_de_cle_stripe_parle_de_tous_les_prelevements(): void
    {
        $texte = (new CleStripeRefusee(401))->render();

        $this->assertStringContainsString('à un prélèvement de la plateforme', $texte);
        $this->assertStringContainsString('ni abonnements, ni packs, ni objets de planète, ni badges', $texte);
        $this->assertStringContainsString('Pour les organisations', $texte);
        $this->assertStringNotContainsString('en prélevant un abonnement', $texte);
    }

    /* ————— Réponse perdue : la même demande renvoyée jusqu'à la réponse (recommandation de Stripe, 26.10.2026) ————— */

    /** @return \Closure(): TestResponse */
    private function acheteurDePack(): \Closure
    {
        return fn () => $this->postJson('/api/v1/organisation/packs', ['type' => 'emails', 'quantite' => 5000], $this->enTetes($this->jeton));
    }

    private function packDeLaFacture(): ?OrganisationFacture
    {
        return OrganisationFacture::query()->get()->first(fn (OrganisationFacture $f) => ($f->metadonnees['type'] ?? null) === 'pack');
    }

    private function verifierLesPaiements(int $minutes = 1): void
    {
        $this->travel($minutes)->minutes();
        $this->artisan('abonnements:verifier-paiements')->assertSuccessful();
    }

    /** La bibliothèque de Stripe renvoie seule une demande restée sans réponse, avec la même clé. */
    public function test_le_client_stripe_des_abonnements_renvoie_seul_apres_une_coupure(): void
    {
        $this->app->forgetInstance(PasserelleAbonnement::class);
        config(['services.stripe.secret' => 'sk_test_essai']);
        $passerelle = $this->app->make(PasserelleAbonnement::class);

        $client = (fn () => $this->client)->call($passerelle);
        $this->assertSame(2, $client->getMaxNetworkRetries());
    }

    /** Une demande jamais arrivée chez Stripe : en vérification, puis renvoyée telle quelle une minute plus tard, prélevée une fois. */
    public function test_un_pack_sans_reponse_jamais_arrive_est_renvoye_et_preleve_une_fois(): void
    {
        $this->abonneeACabinet();
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        ($this->acheteurDePack())()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement est en cours de vérification chez le prestataire : il se termine tout seul dans un instant.']);
        $cle = end($this->stripe->cles);
        $this->getJson('/api/v1/organisation/factures', $this->enTetes($this->jeton))->assertJsonPath('data.0.en_verification', true);
        $this->stripe->panne = null;

        $this->verifierLesPaiements();

        $nouveaux = $this->prelevementsDepuis($avant);
        $this->assertCount(1, $nouveaux);
        $this->assertSame($cle, $nouveaux[0]['cle']);
        $this->assertSame('payee', $this->packDeLaFacture()->statut);
        $this->getJson('/api/v1/organisation/factures', $this->enTetes($this->jeton))->assertJsonPath('data.0.en_verification', false);
    }

    /** Une demande passée chez Stripe mais pas encore dans sa liste : la même demande renvoyée rend le premier prélèvement, jamais un second. */
    public function test_un_pack_sans_reponse_passe_est_repris_sans_second_prelevement(): void
    {
        $this->abonneeACabinet();
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;
        $this->stripe->listeEnRetard = true;
        ($this->acheteurDePack())()->assertUnprocessable();
        $this->stripe->panne = null;
        $this->stripe->passeMalgreErreur = false;

        $this->verifierLesPaiements();

        $this->assertCount(1, $this->prelevementsDepuis($avant));
        $this->assertSame('payee', $this->packDeLaFacture()->statut);
    }

    /** Le serveur arrêté au milieu d'un paiement (déploiement) : la demande, notée avant l'envoi, est renvoyée telle quelle. */
    public function test_un_serveur_arrete_au_milieu_d_un_paiement_le_reprend(): void
    {
        $this->abonneeACabinet();
        $avant = count($this->stripe->prelevements);
        $this->stripe->arret = true;
        $this->withoutExceptionHandling();
        try {
            ($this->acheteurDePack())();
            $this->fail('Arrêt attendu');
        } catch (\RuntimeException) {
        }
        $this->withExceptionHandling();
        $cle = end($this->stripe->cles);
        $this->stripe->arret = false;

        $this->verifierLesPaiements();

        $nouveaux = $this->prelevementsDepuis($avant);
        $this->assertCount(1, $nouveaux);
        $this->assertSame($cle, $nouveaux[0]['cle']);
        $this->assertSame('payee', $this->packDeLaFacture()->statut);
    }

    /** Une nouvelle carte pendant la vérification : la demande renvoyée reste celle d'origine (même carte, même clé), rien sur la nouvelle. */
    public function test_une_nouvelle_carte_pendant_la_verification_ne_change_pas_la_demande(): void
    {
        $this->abonneeACabinet();
        $carte = Organisation::query()->findOrFail($this->organisationId())->stripe_moyen_paiement_id;
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        ($this->acheteurDePack())()->assertUnprocessable();
        $this->stripe->panne = null;
        $this->avecCarte('5555');

        $this->verifierLesPaiements();

        $nouveaux = $this->prelevementsDepuis($avant);
        $this->assertCount(1, $nouveaux);
        $this->assertSame($carte, $nouveaux[0]['moyen']);
    }

    /** Refusée au renvoi : la commande est annulée, comme en présence du client. */
    public function test_un_pack_refuse_au_renvoi_est_annule(): void
    {
        $this->abonneeACabinet();
        $this->stripe->panne = 'Délai dépassé.';
        ($this->acheteurDePack())()->assertUnprocessable();
        $this->stripe->panne = null;
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';

        $this->verifierLesPaiements();

        $this->assertSame('annulee', $this->packDeLaFacture()->statut);
    }

    /** Toujours sans réponse : la commande reste en vérification et bloque un second achat, même une demi-heure plus tard. */
    public function test_un_achat_attend_tant_que_le_precedent_est_sans_reponse(): void
    {
        $this->abonneeACabinet();
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        ($this->acheteurDePack())()->assertUnprocessable();
        $this->verifierLesPaiements(30);
        $this->stripe->panne = null;
        $this->stripe->listeEnRetard = true;

        ($this->acheteurDePack())()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement précédent est encore en vérification chez le prestataire. Réessayez dans une minute.']);
        $this->assertSame([], $this->prelevementsDepuis($avant));
    }

    /** Sans réponse pendant 23 heures (Stripe garde une clé 24 h) : on ne renvoie plus, la liste de Stripe fait foi ; rien n'y est, la commande est annulée. */
    public function test_une_demande_sans_reponse_depuis_23_heures_est_rapprochee_de_la_liste(): void
    {
        $this->abonneeACabinet();
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        ($this->acheteurDePack())()->assertUnprocessable();
        $this->verifierLesPaiements(22 * 60 + 58);
        $this->assertSame('a_payer', $this->packDeLaFacture()->statut);
        $this->verifierLesPaiements(3);

        $this->assertSame('annulee', $this->packDeLaFacture()->statut);
        $this->assertSame([], $this->prelevementsDepuis($avant));
    }

    /** Passée malgré tout, retrouvée dans la liste au bout de 23 heures : payée, rien de plus. */
    public function test_une_demande_passee_retrouvee_apres_23_heures_est_payee(): void
    {
        $this->abonneeACabinet();
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;
        $this->stripe->listeEnPanne = true;
        ($this->acheteurDePack())()->assertUnprocessable();
        $this->stripe->passeMalgreErreur = false;
        $this->stripe->listeEnPanne = false;
        $this->stripe->listeEnRetard = true;
        $this->verifierLesPaiements();
        $this->stripe->listeEnRetard = false;

        $this->verifierLesPaiements(23 * 60);

        $this->assertCount(1, $this->prelevementsDepuis($avant));
        $this->assertSame('payee', $this->packDeLaFacture()->statut);
    }

    /** Une demande envoyée il y a moins d'une minute est encore en route : la vérification ne la renvoie pas. */
    public function test_une_demande_toute_recente_n_est_pas_renvoyee(): void
    {
        $this->abonneeACabinet();
        $this->stripe->panne = 'Délai dépassé.';
        ($this->acheteurDePack())()->assertUnprocessable();
        $cles = count($this->stripe->cles);

        $this->travel(30)->seconds();
        $this->artisan('abonnements:verifier-paiements')->assertSuccessful();

        $this->assertCount($cles, $this->stripe->cles);
    }

    /** Serveur arrêté après que Stripe a prélevé, et la vérification arrêtée elle aussi pendant 23 heures : la liste retrouve le prélèvement, la commande est payée, jamais annulée. */
    public function test_un_arret_apres_le_prelevement_puis_23_heures_sans_reponse_est_paye(): void
    {
        $this->abonneeACabinet();
        $avant = count($this->stripe->prelevements);
        $this->stripe->arret = true;
        $this->stripe->passeMalgreErreur = true;
        $this->withoutExceptionHandling();
        try {
            ($this->acheteurDePack())();
        } catch (\RuntimeException) {
        }
        $this->withExceptionHandling();
        $this->stripe->arret = false;
        $this->stripe->passeMalgreErreur = false;

        $this->verifierLesPaiements(23 * 60 + 1);

        $this->assertCount(1, $this->prelevementsDepuis($avant));
        $this->assertSame('payee', $this->packDeLaFacture()->statut);
    }

    /** Une facture due (minutes d'un abonnement fermé) n'est jamais annulée par la vérification : refusée au renvoi, elle reste à régler. */
    public function test_une_facture_due_refusee_au_renvoi_reste_a_regler(): void
    {
        $this->abonneeACabinet();
        $facture = OrganisationFacture::factory()->create(['organisation_id' => $this->organisationId(), 'statut' => 'a_payer', 'montant' => 12.5, 'metadonnees' => ['type' => 'consommation', 'action' => 'fin']]);
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';

        $this->verifierLesPaiements();

        $this->assertSame('a_payer', $facture->refresh()->statut);
        $this->assertArrayNotHasKey('demande_en_suspens', $facture->metadonnees);
    }

    /** Une facture due sans réponse depuis 23 heures, rien chez Stripe : jamais annulée, de nouveau à régler, avec une nouvelle clé. */
    public function test_une_facture_due_sans_reponse_depuis_23_heures_reste_a_regler(): void
    {
        $this->abonneeACabinet();
        $facture = OrganisationFacture::factory()->create(['organisation_id' => $this->organisationId(), 'statut' => 'a_payer', 'montant' => 12.5, 'metadonnees' => ['type' => 'consommation', 'action' => 'fin']]);
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))->assertUnprocessable();
        $cle = end($this->stripe->cles);

        $this->verifierLesPaiements(23 * 60 + 1);

        $this->assertSame('a_payer', $facture->refresh()->statut);
        $this->assertArrayNotHasKey('demande_en_suspens', $facture->metadonnees);
        $this->stripe->panne = null;
        $this->voyager(now()->timezone('Europe/Zurich')->format('Y-m-d H:i'));
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))->assertOk();
        $this->assertNotSame($cle, end($this->stripe->cles));
    }

    /** Un renouvellement resté 23 heures sans réponse : Stripe a pu oublier la clé ; rien dans sa liste, la tentative suivante prend une nouvelle clé. */
    public function test_un_renouvellement_sans_reponse_depuis_23_heures_prend_une_nouvelle_cle(): void
    {
        $this->abonneeACabinet();
        $this->stripe->panne = 'Délai dépassé.';
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $cle = end($this->stripe->cles);
        $this->stripe->panne = null;

        $this->voyager('2026-11-21 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $this->assertNotSame($cle, end($this->stripe->cles));
        $this->assertSame('2026-12-20', Organisation::query()->findOrFail($this->organisationId())->periode_fin->toDateString());
    }

    /** Un 429 au renvoi ne dit rien de la demande perdue : elle reste en vérification, un second achat attend, jamais deux prélèvements. */
    public function test_un_429_au_renvoi_garde_la_demande_en_verification(): void
    {
        $this->abonneeACabinet();
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;
        $this->stripe->listeEnRetard = true;
        ($this->acheteurDePack())()->assertUnprocessable();
        $this->stripe->panne = null;
        $this->stripe->passeMalgreErreur = false;
        $this->stripe->erreurServeur = 429;
        $this->verifierLesPaiements();
        $this->stripe->erreurServeur = null;
        $this->travel(1)->minutes();

        ($this->acheteurDePack())()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement précédent est encore en vérification chez le prestataire. Réessayez dans une minute.']);
        $this->assertCount(1, $this->prelevementsDepuis($avant));
    }

    /** Une montée encore en vérification : une formule moins chère attend de savoir, rien n'est programmé. */
    public function test_une_descente_attend_une_montee_en_verification(): void
    {
        $this->abonneeACabinet();
        $this->voyager('2026-11-10 10:00');
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'hopital'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;

        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'individuel'], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'Un changement de formule précédent est encore traité par votre banque ou en vérification chez le prestataire. Réessayez dans quelques minutes.']);
        $this->assertNull(Organisation::query()->findOrFail($this->organisationId())->formule_suivante);
    }

    /** « Régler » sans réponse de Stripe : le paiement est en vérification et se termine tout seul. */
    public function test_regler_sans_reponse_dit_que_le_paiement_se_termine_seul(): void
    {
        $facture = $this->factureRefuseeARegler();
        $this->stripe->panne = 'Délai dépassé.';

        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement est en cours de vérification chez le prestataire : il se termine tout seul dans un instant.']);
    }

    /** « Régler » un renouvellement en relance, sans réponse de Stripe : renvoyé dès la minute suivante, payé, sans attendre la relance du lendemain. */
    public function test_regler_une_relance_sans_reponse_est_renvoye_la_minute_suivante(): void
    {
        $this->abonneeAvecRenouvellementRefuse();
        $this->stripe->refus = null;
        $this->voyager('2026-11-20 08:00');
        $facture = OrganisationFacture::query()->where('statut', 'a_payer')->sole();
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;

        $this->verifierLesPaiements();

        $this->assertSame('payee', $facture->refresh()->statut);
        $this->assertSame('actif', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);
    }

    /** Résiliée pendant que le renouvellement attend une réponse de Stripe : la vérification ne le renvoie plus ; rien dans la liste au bout de 23 heures, rien n'est prélevé et l'abonnement se termine (26.10.2026). */
    public function test_une_resiliation_pendant_un_renouvellement_sans_reponse_n_est_pas_prelevee(): void
    {
        $this->abonneeACabinet();
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->verifierLesPaiements();
        $this->voyager('2026-11-20 06:30');
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $this->enTetes($this->jeton))->assertOk();
        $this->stripe->panne = null;

        $this->verifierLesPaiements();
        $this->voyager('2026-11-20 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame([], $this->prelevementsDepuis($avant));

        $this->voyager('2026-11-21 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame([], $this->prelevementsDepuis($avant));
        $this->assertSame('resilie', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);
    }

    /** Résiliée pendant que la relance de la nuit attend une réponse : rien n'est renvoyé, rien n'est prélevé. */
    public function test_une_resiliation_pendant_une_relance_sans_reponse_n_est_pas_prelevee(): void
    {
        $this->abonneeAvecRenouvellementRefuse();
        $this->stripe->refus = null;
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        $this->voyager('2026-11-21 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->voyager('2026-11-21 06:30');
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $this->enTetes($this->jeton))->assertOk();
        $this->stripe->panne = null;

        $this->verifierLesPaiements();

        $this->assertSame([], $this->prelevementsDepuis($avant));
    }

    /** Un renouvellement refusé au renvoi n'est jamais annulé par la vérification : il reste à la relance prévue, qui ne compte rien de plus. */
    public function test_un_renouvellement_refuse_au_renvoi_reste_aux_relances(): void
    {
        $this->abonneeAvecRenouvellementRefuse();
        $this->voyager('2026-11-20 08:00');
        $facture = OrganisationFacture::query()->where('statut', 'a_payer')->sole();
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson("/api/v1/organisation/factures/{$facture->id}/regler", [], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;

        $this->verifierLesPaiements();

        $this->assertSame('a_payer', $facture->refresh()->statut);
        $this->assertArrayNotHasKey('demande_en_suspens', $facture->metadonnees);
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame(['impaye', 1], [$organisation->statut_abonnement, (int) $organisation->tentatives]);
    }

    /** Deux passages de la vérification qui se chevauchent : celui qui arrive après une annulation relit la facture et ne prélève jamais une commande annulée. */
    public function test_une_verification_en_retard_ne_preleve_pas_une_commande_annulee(): void
    {
        $this->abonneeACabinet();
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        ($this->acheteurDePack())()->assertUnprocessable();
        $this->stripe->panne = null;
        $this->travel(1)->minutes();
        $enRetard = $this->packDeLaFacture();
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $this->artisan('abonnements:verifier-paiements')->assertSuccessful();
        $this->assertSame('annulee', $this->packDeLaFacture()->statut);
        $this->stripe->refus = null;

        app(AbonnementSaas::class)->verifierPaiementEnSuspens($enRetard);

        $this->assertSame([], array_values(array_filter($this->prelevementsDepuis($avant), fn (array $p) => $p['statut'] === 'reussi')));
    }

    /** Annulée juste avant le renvoi (entre la relecture de la vérification et le verrou du paiement) : jamais prélevée. */
    public function test_une_commande_annulee_juste_avant_le_renvoi_n_est_pas_prelevee(): void
    {
        $this->abonneeACabinet();
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        ($this->acheteurDePack())()->assertUnprocessable();
        $this->stripe->panne = null;
        $this->travel(1)->minutes();
        $facture = $this->packDeLaFacture();
        $fait = false;
        DB::listen(function (QueryExecuted $requete) use (&$fait, $facture): void {
            if (! $fait && str_contains($requete->sql, 'from "organisation_factures"') && str_starts_with($requete->sql, 'select')) {
                $fait = true;
                OrganisationFacture::query()->whereKey($facture->id)->update(['statut' => 'annulee']);
            }
        });

        app(AbonnementSaas::class)->verifierPaiementEnSuspens($facture);

        $this->assertTrue($fait);
        $this->assertSame([], $this->prelevementsDepuis($avant));
    }

    /** Clé de la plateforme refusée dès le premier envoi : rien n'est parti chez Stripe, rien n'est en vérification ; l'achat est annulé tout de suite. */
    public function test_une_cle_refusee_au_premier_envoi_ne_met_rien_en_verification(): void
    {
        $this->abonneeACabinet();
        $this->stripe->erreurServeur = 401;

        ($this->acheteurDePack())()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => ErreurPaiement::GENERIQUE]);

        $this->assertSame('annulee', $this->packDeLaFacture()->statut);
    }

    /** Trop d'appels (429) dès le premier envoi : Stripe n'a rien traité, rien n'est en vérification ; l'achat est annulé tout de suite. */
    public function test_un_429_au_premier_envoi_ne_met_rien_en_verification(): void
    {
        $this->abonneeACabinet();
        $this->stripe->erreurServeur = 429;

        ($this->acheteurDePack())()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => ErreurPaiement::GENERIQUE]);

        $this->assertSame('annulee', $this->packDeLaFacture()->statut);
    }

    /** Les renouvellements passent chaque heure ; une commande tuée en pleine course (déploiement) ne les bloque que 50 minutes, jamais 24 h (26.10.2026). */
    public function test_les_renouvellements_repartent_apres_une_commande_tuee(): void
    {
        $evenement = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'abonnements:renouveler'));

        $this->assertNotNull($evenement);
        $this->assertSame('0 * * * *', $evenement->expression);
        $this->assertSame(50, $evenement->expiresAt);
    }

    /** Deux passages des renouvellements qui se chevauchent : le second, qui a lu l'organisation avant que le premier la renouvelle, la relit sous son verrou et ne prélève jamais la même période deux fois (26.10.2026). */
    public function test_deux_passages_des_renouvellements_ne_prelevent_pas_deux_fois(): void
    {
        $this->abonneeACabinet();
        $this->voyager('2026-11-20 06:00');
        $enRetard = Organisation::query()->findOrFail($this->organisationId());
        $avant = count($this->stripe->prelevements);

        $this->artisan('abonnements:renouveler')->assertSuccessful();
        app(AbonnementSaas::class)->renouveler($enRetard);

        $this->assertCount(1, $this->prelevementsDepuis($avant));
        $this->assertSame(1, OrganisationFacture::query()->get()->filter(fn (OrganisationFacture $f) => ($f->metadonnees['action'] ?? null) === 'renouvellement')->count());
    }

    /** Un abonné déjà pris par un autre passage (ou par un achat en cours) est laissé à ce passage : rien n'est prélevé ni journalisé en erreur, le passage suivant le reprend. */
    public function test_un_abonne_deja_pris_est_laisse_au_passage_en_cours(): void
    {
        $this->abonneeACabinet();
        $this->voyager('2026-11-20 06:00');
        $avant = count($this->stripe->prelevements);
        $verrou = Cache::lock('abonnement:organisation:'.$this->organisationId(), 600);
        $this->assertTrue($verrou->get());
        Log::spy();

        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $this->assertSame([], $this->prelevementsDepuis($avant));
        Log::shouldNotHaveReceived('error');
        $verrou->release();
        $this->voyager('2026-11-20 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertCount(1, $this->prelevementsDepuis($avant));
    }

    /** Une relance déjà prise par un autre passage : laissée à ce passage, rien n'est prélevé ; le passage suivant la reprend une fois. */
    public function test_une_relance_deja_prise_est_laissee_au_passage_en_cours(): void
    {
        $this->abonneeAvecRenouvellementRefuse();
        $this->stripe->refus = null;
        $this->voyager('2026-11-21 07:00');
        $avant = count($this->stripe->prelevements);
        $verrou = Cache::lock('abonnement:organisation:'.$this->organisationId(), 600);
        $this->assertTrue($verrou->get());

        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $this->assertSame([], $this->prelevementsDepuis($avant));
        $verrou->release();
        $this->voyager('2026-11-21 08:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertCount(1, $this->prelevementsDepuis($avant));
        $this->assertSame('actif', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);
    }

    /** Une ancienne abonnée résiliée (date de résiliation gardée) qui se réabonne pendant une panne : sa souscription est bien renvoyée chaque minute. */
    public function test_une_resouscription_apres_resiliation_est_renvoyee(): void
    {
        $this->abonneeACabinet();
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $this->enTetes($this->jeton))->assertOk();
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame('resilie', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);
        Organisation::query()->whereKey($this->organisationId())->update(['resilie_le' => '2026-11-20']);
        $avant = count($this->stripe->prelevements);
        $this->voyager('2026-11-25 10:00');
        $this->stripe->panne = 'Délai dépassé.';
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'cabinet'], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->stripe->panne = null;

        $this->verifierLesPaiements();

        $this->assertCount(1, $this->prelevementsDepuis($avant));
    }

    /** Renouvellement passé chez Stripe malgré la réponse perdue, puis résiliation : le passage de chaque heure le retrouve, la période est payée une fois et se termine à son échéance sans autre prélèvement. */
    public function test_un_renouvellement_passe_puis_une_resiliation_est_paye_une_fois(): void
    {
        $this->abonneeACabinet();
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;
        $this->stripe->listeEnPanne = true;
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->stripe->panne = null;
        $this->stripe->passeMalgreErreur = false;
        $this->voyager('2026-11-20 06:30');
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $this->enTetes($this->jeton))->assertOk();
        $this->stripe->listeEnPanne = false;

        $this->voyager('2026-11-20 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame(['actif', '2026-12-20'], [$organisation->statut_abonnement, $organisation->periode_fin->toDateString()]);

        $this->voyager('2026-12-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertCount(1, $this->prelevementsDepuis($avant));
        $this->assertSame('resilie', $organisation->refresh()->statut_abonnement);
    }

    /** Un passage en retard qui arrive après la fermeture de l'accès (dernière relance refusée) : la relance relue ne rouvre jamais l'accès sans paiement (26.10.2026). */
    public function test_une_relance_en_retard_ne_rouvre_pas_un_acces_ferme(): void
    {
        $this->jusquALaDerniereRelance();
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $this->voyager('2026-11-23 07:00');
        $enRetard = Organisation::query()->findOrFail($this->organisationId());
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame('resilie', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);

        app(AbonnementSaas::class)->retenter($enRetard);

        $this->assertSame('resilie', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);
    }

    /** Un passage en retard sur une relance tout juste refusée : la prochaine tentative n'est pas encore venue, rien n'est retenté ni compté (26.10.2026). */
    public function test_une_relance_en_retard_ne_compte_pas_une_relance_de_plus(): void
    {
        $this->abonneeAvecRenouvellementRefuse();
        $this->voyager('2026-11-21 07:00');
        $enRetard = Organisation::query()->findOrFail($this->organisationId());
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $cles = count($this->stripe->cles);

        app(AbonnementSaas::class)->retenter($enRetard);

        $this->assertCount($cles, $this->stripe->cles);
        $this->assertSame(2, (int) Organisation::query()->findOrFail($this->organisationId())->tentatives);
    }

    /** Un passage en retard sur un renouvellement tout juste refusé : l'organisation, relue, n'est plus active ; ni relance de plus ni second e-mail (26.10.2026). */
    public function test_un_renouvellement_en_retard_apres_un_refus_ne_compte_rien_de_plus(): void
    {
        $this->abonneeACabinet();
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $this->voyager('2026-11-20 06:00');
        $enRetard = Organisation::query()->findOrFail($this->organisationId());
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        app(AbonnementSaas::class)->renouveler($enRetard);

        $this->assertSame(1, (int) Organisation::query()->findOrFail($this->organisationId())->tentatives);
        Mail::assertSent(PaiementAbonnementRefuse::class, 1);
    }

    /** Une relance passée chez Stripe malgré la réponse perdue, puis une résiliation pendant les relances : la relance suivante la retrouve, la période est payée et l'accès reste ouvert jusqu'à son échéance (26.10.2026). */
    public function test_une_relance_passee_puis_une_resiliation_garde_la_periode_payee(): void
    {
        $this->abonneeAvecRenouvellementRefuse();
        $this->stripe->refus = null;
        $avant = count($this->stripe->prelevements);
        $this->stripe->panne = 'Délai dépassé.';
        $this->stripe->passeMalgreErreur = true;
        $this->stripe->listeEnPanne = true;
        $this->voyager('2026-11-21 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->stripe->panne = null;
        $this->stripe->passeMalgreErreur = false;
        $this->voyager('2026-11-21 07:30');
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $this->enTetes($this->jeton))->assertOk();
        $this->stripe->listeEnPanne = false;

        $this->voyager('2026-11-21 08:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame(['actif', '2026-12-20'], [$organisation->statut_abonnement, $organisation->periode_fin->toDateString()]);
        $this->assertCount(1, $this->prelevementsDepuis($avant));
    }

    /** L'échéance se renouvelle dès minuit et demi, heure de Zurich, alors que l'UTC est encore la veille (26.10.2026). */
    public function test_l_echeance_se_renouvelle_juste_apres_minuit_a_zurich(): void
    {
        $this->abonneeACabinet();
        $this->voyager('2026-11-20 00:30');

        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $this->assertSame('2026-12-20', Organisation::query()->findOrFail($this->organisationId())->periode_fin->toDateString());
    }

    /** La vérification des paiements passe chaque minute. */
    public function test_la_verification_des_paiements_passe_chaque_minute(): void
    {
        $evenement = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'abonnements:verifier-paiements'));

        $this->assertNotNull($evenement);
        $this->assertSame('* * * * *', $evenement->expression);
        // Tuée en pleine vérification (déploiement) : la suivante repart dans dix minutes, pas dans 24 h
        $this->assertSame(10, $evenement->expiresAt);
    }

    /** Un 409 (la même demande en cours) ou un 429 (trop d'appels) au renouvellement : pas un refus, la même demande repart à l'heure suivante et passe (26.10.2026). */
    private function renouvellementApresUneErreur(int $statut): void
    {
        $this->abonneeACabinet();
        $this->stripe->erreurServeur = $statut;
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame(['actif', 0], [$organisation->statut_abonnement, (int) $organisation->tentatives]);
        Mail::assertNotSent(PaiementAbonnementRefuse::class);
        $cle = end($this->stripe->cles);

        $this->stripe->erreurServeur = null;
        $this->voyager('2026-11-20 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame($cle, end($this->stripe->cles));
        $this->assertSame('2026-12-20', $organisation->refresh()->periode_fin->toDateString());
    }

    public function test_un_409_au_renouvellement_n_est_pas_un_refus(): void
    {
        $this->renouvellementApresUneErreur(409);
    }

    public function test_un_429_au_renouvellement_n_est_pas_un_refus(): void
    {
        $this->renouvellementApresUneErreur(429);
    }

    /** Les relances quotidiennes tombent à l'heure pleine, que la commande trouve à son passage, sans glisser d'une heure chaque jour (26.10.2026). */
    public function test_les_relances_quotidiennes_tombent_a_l_heure_pleine(): void
    {
        $this->abonneeACabinet();
        $this->stripe->refus = 'Fonds insuffisants sur la carte.';
        $this->travelTo(CarbonImmutable::parse('2026-11-20 06:00:07', 'Europe/Zurich'));
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame('2026-11-21 06:00:00', $organisation->prochaine_tentative_le->timezone('Europe/Zurich')->format('Y-m-d H:i:s'));
    }

    /** Résiliée pendant que le renouvellement attend une panne de Stripe : la facture de l'échéance est abandonnée, rien n'est prélevé, l'abonnement se termine (26.10.2026). */
    public function test_une_resiliation_pendant_une_panne_du_renouvellement_n_est_pas_prelevee(): void
    {
        $this->abonneeACabinet();
        $this->stripe->erreurServeur = 500;
        $this->voyager('2026-11-20 06:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->stripe->erreurServeur = null;
        $this->voyager('2026-11-20 06:30');
        $this->postJson('/api/v1/organisation/abonnement/resilier', [], $this->enTetes($this->jeton))->assertOk();
        $avant = count($this->stripe->prelevements);

        $this->voyager('2026-11-20 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();

        $this->assertSame([], $this->prelevementsDepuis($avant));
        $this->assertSame('resilie', Organisation::query()->findOrFail($this->organisationId())->statut_abonnement);
        $this->assertSame(0, OrganisationFacture::query()->where('statut', 'a_payer')->count());
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
