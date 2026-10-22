<?php

namespace Tests\Feature\Planning;

use App\Mail\DemandeRendezVousAcceptee;
use App\Mail\DemandeRendezVousEnvoyee;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\Facture;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use App\Paiements\PasserellePaiement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Réservation en ligne : une urgence attend la clinique (sauf si elle ne
 * veut pas les valider) ; un acompte, quand la clinique en demande un, est
 * payé avant que l'heure soit prise, déduit de la facture, rendu si la
 * clinique annule ou si le client annule assez tôt, gardé sinon.
 */
class AcompteReservationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private LignePlanning $ligne;

    private PasserellePaiementFactice $stripe;

    /** Un mardi à venir, 10 h à la clinique */
    private CarbonImmutable $mardi;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->stripe = new PasserellePaiementFactice;
        $this->app->instance(PasserellePaiement::class, $this->stripe);
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich', 'rdv_sans_compte' => true]);
        HoraireOuverture::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        // Au moins trois jours plus tard : plus de 24 h avant, quel que soit le jour du test
        $this->mardi = CarbonImmutable::now('Europe/Zurich')->addDays(2)->next(CarbonImmutable::TUESDAY)->setTime(10, 0);
    }

    /** Dépôt sans compte, à l'heure donnée */
    private function deposer(array $attributs = []): TestResponse
    {
        return $this->postJson('/api/v1/public/demandes', [
            'clinique_id' => $this->clinique->id,
            'patient_name' => 'Louison',
            'espece' => 'CH',
            'nom_proprietaire' => 'Mme Pineau',
            'email' => 'pineau@exemple.ch',
            'telephone' => '079 512 44 08',
            'motif' => 'Vaccin annuel',
            'mode' => 'place',
            'debut_souhaite' => $this->mardi->toIso8601String(),
            ...$attributs,
        ]);
    }

    public function test_une_urgence_attend_la_clinique_sauf_si_elle_ne_veut_pas_les_valider(): void
    {
        $this->deposer(['urgence' => true, 'symptomes' => 'Boite depuis ce matin'])
            ->assertCreated()
            ->assertJsonPath('data.statut', 'a_traiter');
        $this->assertSame(0, RendezVous::withoutGlobalScopes()->count());
        Mail::assertSent(DemandeRendezVousEnvoyee::class);

        $this->clinique->update(['urgences_a_valider' => false]);
        $this->deposer(['urgence' => true, 'symptomes' => 'Boite depuis ce matin', 'debut_souhaite' => $this->mardi->addHour()->toIso8601String()])
            ->assertCreated()
            ->assertJsonPath('data.statut', 'acceptee');
    }

    public function test_sans_compte_le_telephone_est_obligatoire(): void
    {
        $this->deposer(['telephone' => ''])->assertUnprocessable()->assertJsonValidationErrors('telephone');
    }

    public function test_l_acompte_paye_reserve_l_heure_et_se_deduit_de_la_facture(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);

        $depot = $this->deposer()
            ->assertCreated()
            ->assertJsonPath('data.statut', 'a_payer')
            ->assertJsonPath('data.paiement.montant_centimes', 3000);
        $this->assertNotEmpty($depot->json('data.paiement.client_secret'));
        $this->assertSame(0, RendezVous::withoutGlobalScopes()->count());

        // Pas encore payé : rien ne change
        $jeton = $depot->json('data.jeton');
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk()->assertJsonPath('data.statut', 'a_payer');

        // Payé : l'heure est réservée, marquée « acompte », la confirmation part
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")
            ->assertOk()
            ->assertJsonPath('data.statut', 'acceptee')
            ->assertJsonPath('data.acompte.paye', true);

        $rendezVous = RendezVous::withoutGlobalScopes()->sole();
        $this->assertTrue($rendezVous->starts_at->equalTo($this->mardi));
        $this->assertContains('acompte', array_map(fn ($drapeau) => $drapeau->value ?? $drapeau, $rendezVous->flags->all()));
        Mail::assertSent(DemandeRendezVousAcceptee::class, fn ($mail) => $mail->hasTo('pineau@exemple.ch'));

        // La facture du rendez-vous déduit l'acompte
        ['jeton' => $jetonClinique] = $this->employeAvecJeton($this->clinique);
        $rendezVous->update(['status' => 'cours']);
        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $this->enTetes($jetonClinique))->assertOk();
        $facture = Facture::withoutGlobalScopes()->where('rendez_vous_id', $rendezVous->id)->sole();
        $this->assertSame(30.0, (float) $facture->acompte);
        $this->assertSame(round($facture->totalTtc() - 30, 2), $facture->resteAPayer());
    }

    public function test_le_client_qui_annule_assez_tot_est_rembourse_sinon_l_acompte_est_garde(): void
    {
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $this->clinique->update(['acompte_centimes' => 3000, 'acompte_delai_heures' => 24]);

        // Dans plus de 24 h : remboursé
        $loin = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $this->postJson("/api/v1/espace/rendez-vous/{$loin->rendez_vous_id}/annuler", [], $this->enTetes($jetonProprietaire))
            ->assertOk()
            ->assertJsonPath('data.acompte.rembourse', true);
        $this->assertContains($loin->acompte_intention_id, $this->stripe->remboursements);

        // Prévenu trop tard (la clinique demande deux semaines) : gardé
        $this->clinique->update(['acompte_delai_heures' => 336]);
        $proche = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi->addHours(2));
        $this->postJson("/api/v1/espace/rendez-vous/{$proche->rendez_vous_id}/annuler", [], $this->enTetes($jetonProprietaire))
            ->assertOk()
            ->assertJsonPath('data.acompte.rembourse', false);
        $this->assertNotContains($proche->acompte_intention_id, $this->stripe->remboursements);
    }

    public function test_la_clinique_qui_annule_ou_refuse_rend_l_acompte(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonClinique] = $this->employeAvecJeton($this->clinique);

        // Réservé puis annulé par la clinique
        $jeton = $this->deposer()->json('data.jeton');
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk()->assertJsonPath('data.statut', 'acceptee');
        $this->postJson("/api/v1/rendez-vous/{$demande->refresh()->rendez_vous_id}/annuler", [], $this->enTetes($jetonClinique))->assertOk();
        $this->assertContains($demande->acompte_intention_id, $this->stripe->remboursements);

        // L'heure prise pendant le paiement : la demande passe à la clinique, qui refuse
        $autre = $this->deposer(['debut_souhaite' => $this->mardi->addHours(4)->toIso8601String()]);
        $enAttente = DemandeRendezVous::withoutGlobalScopes()->latest('id')->first();
        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->mardi->addHours(4)->utc(),
            'ends_at' => $this->mardi->addHours(5)->utc(),
        ]);
        $this->stripe->conclure($enAttente->acompte_intention_id, 'succeeded');
        $this->postJson('/api/v1/public/demandes/'.$autre->json('data.jeton').'/paiement')
            ->assertOk()
            ->assertJsonPath('data.statut', 'a_traiter');

        $this->postJson("/api/v1/demandes/{$enAttente->id}/refuser", ['motif' => 'agenda'], $this->enTetes($jetonClinique))->assertOk();
        $this->assertContains($enAttente->acompte_intention_id, $this->stripe->remboursements);
    }

    private function reserverAvecAcompte(string $jetonProprietaire, CarbonImmutable $debut): DemandeRendezVous
    {
        $jeton = $this->postJson('/api/v1/espace/demandes', [
            'clinique_id' => $this->clinique->id,
            'patient_name' => 'Louison',
            'espece' => 'CH',
            'motif' => 'Vaccin annuel',
            'mode' => 'place',
            'debut_souhaite' => $debut->toIso8601String(),
        ], $this->enTetes($jetonProprietaire))->assertCreated()->assertJsonPath('data.statut', 'a_payer')->json('data.jeton');

        $demande = DemandeRendezVous::withoutGlobalScopes()->where('jeton', $jeton)->sole();
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk()->assertJsonPath('data.statut', 'acceptee');

        return $demande->refresh();
    }
}
