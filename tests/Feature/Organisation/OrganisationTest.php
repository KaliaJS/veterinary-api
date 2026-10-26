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
use App\Models\DemandeRendezVous;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\OrganisationFacture;
use App\Models\Role;
use App\Models\Societe;
use App\Models\User;
use App\Paiements\PasserelleAbonnement;
use App\Paiements\PasserellePaiement;
use App\Services\Abonnements\AbonnementSaas;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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
        $regler()->assertUnprocessable();
        $this->assertNotSame($sansReponse, array_slice($this->stripe->cles, -1)[0]);
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
            ->assertJsonValidationErrors(['plan' => 'Une souscription est déjà en cours. Patientez un instant.']);
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
            ->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'Une souscription est déjà en cours. Patientez un instant.']);
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
            $this->assertSame(['Une souscription est déjà en cours. Patientez un instant.'], $e->errors()['plan']);
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
        $acheter()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement est en cours de vérification chez le prestataire. Réessayez dans un instant.']);

        // Nouveau clic, Stripe toujours injoignable : rien de prélevé
        $this->stripe->erreurServeur = null;
        $this->stripe->passeMalgreErreur = false;
        $acheter()->assertUnprocessable()->assertJsonValidationErrors(['paiement' => 'Le paiement précédent est encore en vérification chez le prestataire. Réessayez dans un instant.']);
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

    /** Une panne de Stripe au renouvellement n'est pas un refus de la carte : ni relance consommée, ni e-mail « paiement refusé », nouvel essai une heure plus tard (26.10.2026). */
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

        $organisation = Organisation::query()->findOrFail($this->organisationId());
        $this->assertSame('impaye', $organisation->statut_abonnement);
        $this->assertSame(0, (int) $organisation->tentatives);
        $this->assertSame('2026-11-20 07:00', $organisation->prochaine_tentative_le->timezone('Europe/Zurich')->format('Y-m-d H:i'));
        Mail::assertNotSent(PaiementAbonnementRefuse::class);

        // Stripe répond de nouveau une heure plus tard : payé
        $this->stripe->erreurServeur = null;
        $this->voyager('2026-11-20 07:00');
        $this->artisan('abonnements:renouveler')->assertSuccessful();
        $this->assertSame('actif', $organisation->refresh()->statut_abonnement);
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
