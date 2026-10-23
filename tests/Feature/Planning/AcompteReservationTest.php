<?php

namespace Tests\Feature\Planning;

use App\Mail\AnnulationRendezVousDecidee;
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
 * payé avant que l'heure soit prise et déduit de la facture. Il n'est
 * jamais rendu d'office : le client demande l'annulation, la clinique
 * décide (tout rendre, rendre moins les frais, garder, ou maintenir).
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
        Mail::assertSent(DemandeRendezVousAcceptee::class, fn ($mail) => $mail->hasTo('pineau@exemple.ch')
            && str_contains($mail->render(), 'pas remboursé si vous ne venez pas'));

        // La facture du rendez-vous déduit l'acompte
        ['jeton' => $jetonClinique] = $this->employeAvecJeton($this->clinique);
        $rendezVous->update(['status' => 'cours']);
        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $this->enTetes($jetonClinique))->assertOk();
        $facture = Facture::withoutGlobalScopes()->where('rendez_vous_id', $rendezVous->id)->sole();
        $this->assertSame(30.0, (float) $facture->acompte);
        $this->assertSame(round($facture->totalTtc() - 30, 2), $facture->resteAPayer());
    }

    public function test_le_client_n_annule_plus_un_rendez_vous_avec_acompte_il_demande_l_annulation(): void
    {
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonClinique] = $this->employeAvecJeton($this->clinique);
        $this->clinique->update(['acompte_centimes' => 3000]);
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        // Les frais du paiement sont relevés au paiement : 2,9 % + 30 centimes, plus 0,5 % de commission
        $this->assertSame(132, $demande->acompte_frais_centimes);

        $this->getJson('/api/v1/espace/rendez-vous', $this->enTetes($jetonProprietaire))
            ->assertJsonPath('data.a_venir.0.peut_annuler', false)
            ->assertJsonPath('data.a_venir.0.peut_demander_annulation', true);
        $this->postJson("/api/v1/espace/rendez-vous/{$demande->rendez_vous_id}/annuler", [], $this->enTetes($jetonProprietaire))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->postJson("/api/v1/espace/rendez-vous/{$demande->rendez_vous_id}/demander-annulation", ['motif' => 'Je pars en voyage'], $this->enTetes($jetonProprietaire))
            ->assertOk()
            ->assertJsonPath('data.peut_demander_annulation', false)
            ->assertJsonPath('data.acompte.annulation.decision', null)
            ->assertJsonPath('data.statut', 'prevu');
        $this->assertSame([], $this->stripe->remboursements);
        $this->assertContains('annulation', $this->drapeaux($demande->rendez_vous_id));
        // Une seule demande
        $this->postJson("/api/v1/espace/rendez-vous/{$demande->rendez_vous_id}/demander-annulation", [], $this->enTetes($jetonProprietaire))
            ->assertUnprocessable();

        // Dans la cloche de la clinique, avec les frais connus
        $this->getJson('/api/v1/demandes', $this->enTetes($jetonClinique))
            ->assertOk()
            ->assertJsonPath('data.0.annulation.motif', 'Je pars en voyage')
            ->assertJsonPath('data.0.annulation.acompte', 30)
            ->assertJsonPath('data.0.annulation.frais', 1.32);
    }

    public function test_la_clinique_decide_de_rendre_tout_moins_les_frais_rien_ou_de_maintenir(): void
    {
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonClinique] = $this->employeAvecJeton($this->clinique);
        $this->clinique->update(['acompte_centimes' => 3000]);

        $attendus = ['complet' => 3000, 'hors_frais' => 2868, 'sans' => null, 'refusee' => null];
        $semaine = 0;
        foreach ($attendus as $decision => $rendu) {
            $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi->addWeeks($semaine++));
            $this->postJson("/api/v1/espace/rendez-vous/{$demande->rendez_vous_id}/demander-annulation", [], $this->enTetes($jetonProprietaire))->assertOk();

            $this->postJson("/api/v1/demandes/{$demande->id}/annulation", ['decision' => $decision], $this->enTetes($jetonClinique))
                ->assertOk()
                ->assertJsonPath('data.annulation.decision', $decision)
                ->assertJsonPath('data.annulation.rendu', ($rendu ?? 0) / 100);

            $rendezVous = RendezVous::withoutGlobalScopes()->find($demande->rendez_vous_id);
            $this->assertSame($decision === 'refusee' ? 'prevu' : 'annule', $rendezVous->status->value, $decision);
            $this->assertNotContains('annulation', $this->drapeaux($rendezVous->id));
            $this->assertSame($rendu, $this->stripe->montantsRembourses[$demande->acompte_intention_id] ?? null, $decision);
            Mail::assertSent(AnnulationRendezVousDecidee::class, fn ($mail) => $mail->demande->is($demande)
                && str_contains($mail->render(), $decision === 'refusee' ? 'Rendez-vous maintenu' : 'Rendez-vous annulé'));
        }

        // Déjà tranchée
        $this->postJson("/api/v1/demandes/{$demande->id}/annulation", ['decision' => 'complet'], $this->enTetes($jetonClinique))->assertUnprocessable();
    }

    /** @return list<string> */
    private function drapeaux(int $rendezVousId): array
    {
        return array_map(fn ($drapeau) => $drapeau->value ?? $drapeau, RendezVous::withoutGlobalScopes()->find($rendezVousId)->flags->all());
    }

    public function test_la_clinique_qui_annule_choisit_ce_qu_elle_rend_et_qui_refuse_rend_tout(): void
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

        // Elle peut aussi garder l'acompte (le client a appelé pour annuler)
        $garde = $this->deposer(['debut_souhaite' => $this->mardi->addHours(2)->toIso8601String()]);
        $gardee = DemandeRendezVous::withoutGlobalScopes()->latest('id')->first();
        $this->stripe->conclure($gardee->acompte_intention_id, 'succeeded');
        $this->postJson('/api/v1/public/demandes/'.$garde->json('data.jeton').'/paiement')->assertOk()->assertJsonPath('data.statut', 'acceptee');
        $this->postJson("/api/v1/rendez-vous/{$gardee->refresh()->rendez_vous_id}/annuler", ['acompte' => 'sans'], $this->enTetes($jetonClinique))->assertOk();
        $this->assertNotContains($gardee->acompte_intention_id, $this->stripe->remboursements);

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
