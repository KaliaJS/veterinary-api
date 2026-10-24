<?php

namespace Tests\Feature\Planning;

use App\Enums\DomainePermission;
use App\Enums\StatutRendezVous;
use App\Mail\AcompteConteste;
use App\Mail\AnnulationRendezVousDecidee;
use App\Mail\DemandeRendezVousAcceptee;
use App\Mail\DemandeRendezVousEnvoyee;
use App\Mail\DemandeRendezVousRefusee;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\DerogationPermission;
use App\Models\Facture;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\RendezVous;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;
use App\Services\Paiements\AcomptesRendezVous;
use App\Services\Paiements\WebhooksStripe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\DeposeSansCompte;
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
    use DeposeSansCompte;
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
        $code = $this->codeDeDemande($this->clinique->id, $attributs['email'] ?? 'pineau@exemple.ch');

        return $this->postJson('/api/v1/public/demandes', [
            'code' => $code,
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

    public function test_une_urgence_reservee_directement_paie_aussi_l_acompte(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);

        // Validée par la clinique : pas d'acompte
        $this->deposer(['urgence' => true, 'symptomes' => 'Boite depuis ce matin'])
            ->assertCreated()
            ->assertJsonPath('data.statut', 'a_traiter');

        // Réservée directement : l'acompte comme pour tout rendez-vous
        $this->clinique->update(['urgences_a_valider' => false]);
        $this->deposer(['urgence' => true, 'symptomes' => 'Boite depuis ce matin', 'debut_souhaite' => $this->mardi->addHour()->toIso8601String()])
            ->assertCreated()
            ->assertJsonPath('data.statut', 'a_payer')
            ->assertJsonPath('data.paiement.montant_centimes', 3000);
        $this->assertSame(0, RendezVous::withoutGlobalScopes()->count());
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

    public function test_une_demande_avec_acompte_ne_se_supprime_pas_et_un_paiement_tardif_est_rendu(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        $jeton = $this->deposer()->assertCreated()->json('data.jeton');
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        ['jeton' => $jetonClinique] = $this->employeAvecJeton($this->clinique);

        // Le paiement peut arriver : la demande ne disparaît pas
        $this->deleteJson("/api/v1/demandes/{$demande->id}", [], $this->enTetes($jetonClinique))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('demande');

        // Close entre-temps (abandonnée), puis payée quand même : rien ne se réserve, tout est rendu
        $demande->update(['statut' => 'refusee']);
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk()->assertJsonPath('data.statut', 'refusee');

        $this->assertSame(0, RendezVous::withoutGlobalScopes()->count());
        $this->assertSame([$demande->acompte_intention_id], $this->stripe->remboursements);
        $this->assertSame(3000, $this->stripe->montantsRembourses[$demande->acompte_intention_id]);
        $this->assertNotNull($demande->refresh()->acompte_rembourse_le);

        // Rendu, elle peut être retirée
        $this->deleteJson("/api/v1/demandes/{$demande->id}", [], $this->enTetes($jetonClinique))->assertNoContent();
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['annulation' => 'Votre demande d’annulation est déjà chez la clinique.']);

        // Dans la cloche de la clinique, avec les frais connus
        $this->getJson('/api/v1/demandes', $this->enTetes($jetonClinique))
            ->assertOk()
            ->assertJsonPath('data.0.annulation.motif', 'Je pars en voyage')
            ->assertJsonPath('data.0.annulation.acompte', 30)
            ->assertJsonPath('data.0.annulation.frais', 1.32)
            ->assertJsonPath('data.0.annulation.rendu_hors_frais', 28.68);
    }

    public function test_la_clinique_decide_de_rendre_tout_moins_les_frais_rien_ou_de_maintenir(): void
    {
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonClinique] = $this->administrateurAvecJeton($this->clinique);
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
        $this->postJson("/api/v1/demandes/{$demande->id}/annulation", ['decision' => 'complet'], $this->enTetes($jetonClinique))->assertUnprocessable()->assertJsonValidationErrors(['decision' => 'Aucune demande d’annulation en attente.']);
    }

    /** @return list<string> */
    private function drapeaux(int $rendezVousId): array
    {
        return array_map(fn ($drapeau) => $drapeau->value ?? $drapeau, RendezVous::withoutGlobalScopes()->find($rendezVousId)->flags->all());
    }

    public function test_la_clinique_qui_annule_choisit_ce_qu_elle_rend_et_qui_refuse_rend_tout(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonClinique] = $this->administrateurAvecJeton($this->clinique);

        // Réservé puis annulé par la clinique
        $jeton = $this->deposer()->json('data.jeton');
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk()->assertJsonPath('data.statut', 'acceptee');
        $this->postJson("/api/v1/rendez-vous/{$demande->refresh()->rendez_vous_id}/annuler", [], $this->enTetes($jetonClinique))->assertOk();
        // Annuler une seconde fois ne rend rien de plus : l'acompte est rendu une seule fois
        $this->postJson("/api/v1/rendez-vous/{$demande->rendez_vous_id}/annuler", [], $this->enTetes($jetonClinique))->assertUnprocessable();
        $this->assertCount(1, array_filter($this->stripe->appelsDe('rembourser'), fn (array $appel) => $appel['objet'] === $demande->acompte_intention_id));

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
        $this->assertCount(1, array_keys($this->stripe->remboursements, $enAttente->acompte_intention_id, true));
    }

    /** Refuser une demande dont l'acompte est payé le rend, sans le droit « Rembourser » : un rendu obligé, pas une décision (24.10.2026). */
    public function test_refuser_une_demande_a_acompte_le_rend_sans_le_droit_de_rembourser(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        // Vétérinaire par défaut : planning, pas « Rembourser »
        ['jeton' => $jetonVeto] = $this->employeAvecJeton($this->clinique);
        $autre = $this->deposer();
        $enAttente = DemandeRendezVous::withoutGlobalScopes()->sole();
        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->mardi->utc(), 'ends_at' => $this->mardi->addHour()->utc(),
        ]);
        $this->stripe->conclure($enAttente->acompte_intention_id, 'succeeded');
        $this->postJson('/api/v1/public/demandes/'.$autre->json('data.jeton').'/paiement')->assertOk()->assertJsonPath('data.statut', 'a_traiter');

        $this->postJson("/api/v1/demandes/{$enAttente->id}/refuser", ['motif' => 'agenda'], $this->enTetes($jetonVeto))->assertOk();

        $this->assertCount(1, array_keys($this->stripe->remboursements, $enAttente->acompte_intention_id, true));
        $this->assertNotNull($enAttente->refresh()->acompte_rembourse_le);
    }

    /** Rendre ou garder un acompte payé est une décision d'argent : droit « Rembourser » (24.10.2026). */
    public function test_decider_d_un_acompte_paye_demande_le_droit_de_rembourser(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        // Vétérinaire par défaut : planning, pas « Rembourser »
        ['jeton' => $jetonVeto] = $this->employeAvecJeton($this->clinique);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();

        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $this->postJson("/api/v1/rendez-vous/{$demande->rendez_vous_id}/annuler", ['acompte' => 'sans'], $this->enTetes($jetonVeto))->assertForbidden();
        $this->assertSame('prevu', RendezVous::withoutGlobalScopes()->find($demande->rendez_vous_id)->status->value);

        $this->postJson("/api/v1/espace/rendez-vous/{$demande->rendez_vous_id}/demander-annulation", [], $this->enTetes($jetonProprietaire))->assertOk();
        $this->postJson("/api/v1/demandes/{$demande->id}/annulation", ['decision' => 'complet'], $this->enTetes($jetonVeto))->assertForbidden();
        // Maintenir le rendez-vous ne touche pas à l'argent
        $this->postJson("/api/v1/demandes/{$demande->id}/annulation", ['decision' => 'refusee'], $this->enTetes($jetonVeto))->assertOk();
        $this->assertSame([], $this->stripe->appelsDe('rembourser'));

        // Un rendez-vous sans acompte s'annule avec le seul planning
        $sansAcompte = RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->mardi->addDays(2)->utc(),
            'ends_at' => $this->mardi->addDays(2)->addMinutes(20)->utc(),
        ]);
        $this->postJson("/api/v1/rendez-vous/{$sansAcompte->id}/annuler", [], $this->enTetes($jetonVeto))->assertOk();
    }

    /** L'absence du praticien annule le rendez-vous : l'acompte est rendu en entier, sans le droit « Rembourser » (24.10.2026). */
    public function test_une_absence_rend_l_acompte_des_rendez_vous_qu_elle_annule(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $autre = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi->addHour());

        // Qui gère l'équipe, sans le droit de rembourser
        ['employe' => $rh, 'jeton' => $jetonRh] = $this->administrateurAvecJeton($this->clinique);
        DerogationPermission::create(['employe_id' => $rh->id, 'domaine' => DomainePermission::Rembourser, 'est_accordee' => false]);

        $this->postJson('/api/v1/absences', [
            'employe_id' => $this->ligne->employe_id,
            'type' => 'maladie',
            'statut' => 'ok',
            'debut_le' => $this->mardi->toDateString(),
            'fin_le' => $this->mardi->toDateString(),
        ], $this->enTetes($jetonRh))->assertCreated()->assertJsonPath('meta.rendez_vous_annules', 2);

        foreach ([$demande, $autre] as $d) {
            $d->refresh();
            $this->assertSame(3000, $d->acompte_rembourse_centimes);
            $this->assertSame('annule', RendezVous::withoutGlobalScopes()->find($d->rendez_vous_id)->status->value);
        }
        $this->assertCount(2, $this->stripe->rendus);
        $this->assertSame([3000, 3000], array_column($this->stripe->rendus, 'centimes'));
    }

    /** Le prestataire refuse le rendu : le rendez-vous n'est pas annulé, l'acompte n'est pas perdu. */
    public function test_une_absence_n_annule_pas_un_rendez_vous_dont_l_acompte_ne_peut_etre_rendu(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);

        $this->stripe->erreurs['rembourser'] = new ErreurPaiement(ErreurPaiement::GENERIQUE, 'api_error');
        $this->postJson('/api/v1/absences', [
            'employe_id' => $this->ligne->employe_id,
            'type' => 'maladie',
            'statut' => 'ok',
            'debut_le' => $this->mardi->toDateString(),
            'fin_le' => $this->mardi->toDateString(),
        ], $this->enTetes($jetonAdmin))->assertCreated()->assertJsonPath('meta.rendez_vous_annules', 0)
            // La réponse dit ce qui reste au planning, à reprendre à la main
            ->assertJsonPath('meta.rendez_vous_restants', 1);

        $this->assertNull($demande->refresh()->acompte_rembourse_le);
        $this->assertSame('prevu', RendezVous::withoutGlobalScopes()->find($demande->rendez_vous_id)->status->value);
    }

    /** Rendu depuis Stripe (composant des paiements) : l'app le sait, et ne le rend pas une seconde fois. */
    public function test_un_acompte_rendu_chez_stripe_est_note(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);

        app(WebhooksStripe::class)->traiter([
            'id' => 'evt_acompte_rendu',
            'type' => 'charge.refunded',
            'objet' => ['payment_intent' => $demande->acompte_intention_id, 'refunded' => true, 'amount_refunded' => 3000],
            'compte' => $demande->acompte_stripe_compte_id,
        ]);

        $demande->refresh();
        $this->assertNotNull($demande->acompte_rembourse_le);
        $this->assertSame(3000, $demande->acompte_rembourse_centimes);
        $this->assertFalse($demande->acompteEnJeu());
    }

    /** Contesté à la banque : la facturation le sait tout de suite ; perdu, l'argent est retourné. */
    public function test_un_acompte_conteste_alerte_la_facturation(): void
    {
        Mail::fake();
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $webhook = fn (string $id, string $type, array $objet) => app(WebhooksStripe::class)->traiter([
            'id' => $id, 'type' => $type, 'objet' => $objet, 'compte' => $demande->acompte_stripe_compte_id,
        ]);

        $webhook('evt_acompte_l1', 'charge.dispute.created', ['payment_intent' => $demande->acompte_intention_id, 'evidence_details' => ['due_by' => now()->addDays(7)->timestamp]]);
        $this->assertSame('ouvert', $demande->refresh()->acompte_litige);
        Mail::assertSent(AcompteConteste::class, fn (AcompteConteste $mail) => $mail->demande->is($demande) && $mail->echeance !== null);
        $alertes = collect($this->getJson('/api/v1/alertes', $this->enTetes($jetonAdmin))->assertOk()->json('data.alertes'))->where('type', 'paiement');
        $this->assertContains('1 acompte contesté', $alertes->pluck('titre')->all());

        $webhook('evt_acompte_l2', 'charge.dispute.closed', ['payment_intent' => $demande->acompte_intention_id, 'status' => 'lost']);
        $demande->refresh();
        $this->assertSame('perdu', $demande->acompte_litige);
        $this->assertNotNull($demande->acompte_rembourse_le);
        $this->assertFalse($demande->acompteEnJeu());
    }

    /** Rendu chez Stripe après la facture qui le déduit : la facturation est prévenue (24.10.2026). */
    public function test_un_acompte_rendu_apres_la_facture_alerte_la_facturation(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $rendezVous = RendezVous::withoutGlobalScopes()->sole();
        $rendezVous->update(['status' => 'cours']);
        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $this->enTetes($jetonAdmin))->assertOk();
        $titres = fn () => collect($this->getJson('/api/v1/alertes', $this->enTetes($jetonAdmin))->assertOk()->json('data.alertes'))->pluck('titre')->all();
        $this->assertNotContains('1 acompte rendu après la facture', $titres());

        // Rendu en partie chez Stripe : 10 francs sur 30
        app(WebhooksStripe::class)->traiter([
            'id' => 'evt_acompte_partiel', 'type' => 'charge.refunded',
            'objet' => ['payment_intent' => $demande->acompte_intention_id, 'refunded' => false, 'amount_refunded' => 1000],
            'compte' => $demande->acompte_stripe_compte_id,
        ]);
        $this->assertSame(20.0, $demande->refresh()->acompteRetenu());
        $alerte = collect($this->getJson('/api/v1/alertes', $this->enTetes($jetonAdmin))->json('data.alertes'))->firstWhere('titre', '1 acompte rendu après la facture');
        $this->assertNotNull($alerte);
        $this->assertStringContainsString('CHF 30.00 déduits, CHF 20.00 encore encaissés', str_replace("\u{a0}", ' ', $alerte['detail']));
    }

    /** Perdu au litige après la facture qui le déduit : la même alerte, le client doit la différence. */
    public function test_un_acompte_perdu_au_litige_apres_la_facture_alerte(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $rendezVous = RendezVous::withoutGlobalScopes()->sole();
        $rendezVous->update(['status' => 'cours']);
        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $this->enTetes($jetonAdmin))->assertOk();
        $webhook = fn (string $id, string $type, array $objet) => app(WebhooksStripe::class)->traiter(['id' => $id, 'type' => $type, 'objet' => $objet, 'compte' => $demande->acompte_stripe_compte_id]);

        $webhook('evt_perdu_1', 'charge.dispute.created', ['payment_intent' => $demande->acompte_intention_id]);
        $webhook('evt_perdu_2', 'charge.dispute.closed', ['payment_intent' => $demande->acompte_intention_id, 'status' => 'lost']);

        $alerte = collect($this->getJson('/api/v1/alertes', $this->enTetes($jetonAdmin))->json('data.alertes'))->firstWhere('titre', '1 acompte rendu après la facture');
        $this->assertNotNull($alerte);
        $this->assertStringContainsString('CHF 0.00 encore encaissés', str_replace("\u{a0}", ' ', $alerte['detail']));
    }

    /** Rendu en partie chez Stripe avant toute facture : la facturation le sait (24.10.2026). */
    public function test_un_acompte_rendu_en_partie_avant_la_facture_alerte(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $alertes = fn () => collect($this->getJson('/api/v1/alertes', $this->enTetes($jetonAdmin))->assertOk()->json('data.alertes'));

        app(WebhooksStripe::class)->traiter([
            'id' => 'evt_partiel_avant', 'type' => 'charge.refunded',
            'objet' => ['payment_intent' => $demande->acompte_intention_id, 'refunded' => false, 'amount_refunded' => 1000],
            'compte' => $demande->acompte_stripe_compte_id,
        ]);

        $alerte = $alertes()->firstWhere('titre', '1 acompte rendu en partie');
        $this->assertNotNull($alerte);
        $this->assertStringContainsString('CHF 10.00 rendus sur CHF 30.00', str_replace("\u{a0}", ' ', $alerte['detail']));

        // Rendu en entier ensuite : plus « en partie »
        app(WebhooksStripe::class)->traiter([
            'id' => 'evt_partiel_tout', 'type' => 'charge.refunded',
            'objet' => ['payment_intent' => $demande->acompte_intention_id, 'refunded' => true, 'amount_refunded' => 3000],
            'compte' => $demande->acompte_stripe_compte_id,
        ]);
        $this->assertNull($alertes()->firstWhere('titre', '1 acompte rendu en partie'));
    }

    /** Acompte rendu (par l'app, chez Stripe ou perdu au litige) : le rendez-vous perd son drapeau « acompte » (24.10.2026). */
    public function test_un_acompte_rendu_retire_le_drapeau_acompte_du_rendez_vous(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonClinique] = $this->administrateurAvecJeton($this->clinique);
        $webhook = fn (DemandeRendezVous $demande, string $id, string $type, array $objet) => app(WebhooksStripe::class)->traiter([
            'id' => $id, 'type' => $type, 'objet' => ['payment_intent' => $demande->acompte_intention_id, ...$objet], 'compte' => $demande->acompte_stripe_compte_id,
        ]);

        // Rendu en entier chez Stripe, le rendez-vous reste prévu
        $chezStripe = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $this->assertContains('acompte', $this->drapeaux($chezStripe->rendez_vous_id));
        $webhook($chezStripe, 'evt_drapeau_1', 'charge.refunded', ['refunded' => true, 'amount_refunded' => 3000]);
        $this->assertNotContains('acompte', $this->drapeaux($chezStripe->rendez_vous_id));

        // Perdu au litige
        $litige = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi->addWeek());
        $webhook($litige, 'evt_drapeau_2', 'charge.dispute.created', []);
        $webhook($litige, 'evt_drapeau_3', 'charge.dispute.closed', ['status' => 'lost']);
        $this->assertNotContains('acompte', $this->drapeaux($litige->rendez_vous_id));

        // Rendu par l'app, à la demande d'annulation
        $parLApp = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi->addWeeks(2));
        $this->postJson("/api/v1/espace/rendez-vous/{$parLApp->rendez_vous_id}/demander-annulation", [], $this->enTetes($jetonProprietaire))->assertOk();
        $this->postJson("/api/v1/demandes/{$parLApp->id}/annulation", ['decision' => 'complet'], $this->enTetes($jetonClinique))->assertOk();
        $this->assertNotContains('acompte', $this->drapeaux($parLApp->rendez_vous_id));
    }

    /** Perdu au litige plus de 30 jours après un rendu partiel, facture faite : l'alerte revient, datée du litige (24.10.2026). */
    public function test_un_litige_perdu_longtemps_apres_un_rendu_partiel_alerte_de_nouveau(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $rendezVous = RendezVous::withoutGlobalScopes()->sole();
        $rendezVous->update(['status' => 'cours']);
        $this->postJson("/api/v1/rendez-vous/{$rendezVous->id}/statut", ['status' => 'fini'], $this->enTetes($jetonAdmin))->assertOk();
        $webhook = fn (string $id, string $type, array $objet) => app(WebhooksStripe::class)->traiter(['id' => $id, 'type' => $type, 'objet' => ['payment_intent' => $demande->acompte_intention_id, ...$objet], 'compte' => $demande->acompte_stripe_compte_id]);
        $alerte = fn () => collect($this->getJson('/api/v1/alertes', $this->enTetes($jetonAdmin))->assertOk()->json('data.alertes'))->firstWhere('titre', '1 acompte rendu après la facture');

        $webhook('evt_long_1', 'charge.refunded', ['refunded' => false, 'amount_refunded' => 1000]);
        $this->assertNotNull($alerte());

        // Ce rendu date de 40 jours : l'alerte s'est éteinte
        $demande->refresh()->update(['acompte_rembourse_le' => now()->subDays(40)]);
        $this->assertNull($alerte());

        $webhook('evt_long_2', 'charge.dispute.created', []);
        $webhook('evt_long_3', 'charge.dispute.closed', ['status' => 'lost']);
        $this->assertEqualsWithDelta(now()->timestamp, $demande->refresh()->acompte_rembourse_le->timestamp, 5);
        $this->assertNotNull($alerte());
        $this->assertStringContainsString('CHF 0.00 encore encaissés', str_replace("\u{a0}", ' ', $alerte()['detail']));
    }

    /** « Rendre moins les frais » décidé dans l'app pour un rendez-vous annulé : pas une alerte de rendu partiel (24.10.2026). */
    public function test_rendre_moins_les_frais_n_alerte_pas_d_un_rendu_partiel(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonClinique] = $this->administrateurAvecJeton($this->clinique);
        $alertes = fn () => collect($this->getJson('/api/v1/alertes', $this->enTetes($jetonClinique))->assertOk()->json('data.alertes'))->pluck('titre')->all();

        // Demandée par le client, décidée par la clinique
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $this->postJson("/api/v1/espace/rendez-vous/{$demande->rendez_vous_id}/demander-annulation", [], $this->enTetes($jetonProprietaire))->assertOk();
        $this->postJson("/api/v1/demandes/{$demande->id}/annulation", ['decision' => 'hors_frais'], $this->enTetes($jetonClinique))->assertOk();
        $this->assertSame(2868, $demande->refresh()->acompte_rembourse_centimes);
        $this->assertNotContains('1 acompte rendu en partie', $alertes());

        // Annulée par la clinique, moins les frais
        $autre = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi->addWeek());
        $this->postJson("/api/v1/rendez-vous/{$autre->rendez_vous_id}/annuler", ['acompte' => 'hors_frais'], $this->enTetes($jetonClinique))->assertOk();
        $this->assertSame(2868, $autre->refresh()->acompte_rembourse_centimes);
        $this->assertNotContains('1 acompte rendu en partie', $alertes());
    }

    /** Annulation refusée, rendez-vous maintenu, puis rendu en partie chez Stripe : l'alerte part (24.10.2026). */
    public function test_un_rendu_partiel_apres_une_annulation_refusee_alerte(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonClinique] = $this->administrateurAvecJeton($this->clinique);
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $this->postJson("/api/v1/espace/rendez-vous/{$demande->rendez_vous_id}/demander-annulation", [], $this->enTetes($jetonProprietaire))->assertOk();
        $this->postJson("/api/v1/demandes/{$demande->id}/annulation", ['decision' => 'refusee'], $this->enTetes($jetonClinique))->assertOk();

        app(WebhooksStripe::class)->traiter([
            'id' => 'evt_refusee_partiel', 'type' => 'charge.refunded',
            'objet' => ['payment_intent' => $demande->acompte_intention_id, 'refunded' => false, 'amount_refunded' => 1000],
            'compte' => $demande->acompte_stripe_compte_id,
        ]);

        $titres = collect($this->getJson('/api/v1/alertes', $this->enTetes($jetonClinique))->assertOk()->json('data.alertes'))->pluck('titre')->all();
        $this->assertContains('1 acompte rendu en partie', $titres);
    }

    /** Payée pendant que l'heure était prise (pas encore de rendez-vous), rendue en partie chez Stripe : l'alerte part aussi (24.10.2026). */
    public function test_un_rendu_partiel_d_une_demande_payee_sans_rendez_vous_alerte(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonClinique] = $this->administrateurAvecJeton($this->clinique);
        $jeton = $this->deposer()->json('data.jeton');
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->mardi->utc(),
            'ends_at' => $this->mardi->addHour()->utc(),
        ]);
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk()->assertJsonPath('data.statut', 'a_traiter');
        $this->assertNull($demande->refresh()->rendez_vous_id);

        app(WebhooksStripe::class)->traiter([
            'id' => 'evt_sans_rdv_partiel', 'type' => 'charge.refunded',
            'objet' => ['payment_intent' => $demande->acompte_intention_id, 'refunded' => false, 'amount_refunded' => 1000],
            'compte' => $demande->acompte_stripe_compte_id,
        ]);

        $titres = collect($this->getJson('/api/v1/alertes', $this->enTetes($jetonClinique))->assertOk()->json('data.alertes'))->pluck('titre')->all();
        $this->assertContains('1 acompte rendu en partie', $titres);
    }

    /** Rendu en partie chez Stripe, puis la demande refusée : le reste est rendu, une fois (24.10.2026). */
    public function test_une_demande_refusee_apres_un_rendu_partiel_rend_le_reste(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonClinique] = $this->administrateurAvecJeton($this->clinique);
        $jeton = $this->deposer()->json('data.jeton');
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->mardi->utc(),
            'ends_at' => $this->mardi->addHour()->utc(),
        ]);
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk()->assertJsonPath('data.statut', 'a_traiter');
        app(WebhooksStripe::class)->traiter([
            'id' => 'evt_partiel_refus', 'type' => 'charge.refunded',
            'objet' => ['payment_intent' => $demande->acompte_intention_id, 'refunded' => false, 'amount_refunded' => 1000],
            'compte' => $demande->acompte_stripe_compte_id,
        ]);

        $this->postJson("/api/v1/demandes/{$demande->id}/refuser", ['motif' => 'agenda'], $this->enTetes($jetonClinique))->assertOk();

        $this->assertSame(2000, $this->stripe->montantsRembourses[$demande->acompte_intention_id] ?? null);
        $this->assertSame(3000, $demande->refresh()->acompte_rembourse_centimes);
        $titres = collect($this->getJson('/api/v1/alertes', $this->enTetes($jetonClinique))->assertOk()->json('data.alertes'))->pluck('titre')->all();
        $this->assertNotContains('1 acompte rendu en partie', $titres);
        // Plus rien à rendre
        $this->assertFalse(app(AcomptesRendezVous::class)->rembourser($demande->refresh()));
    }

    /** Payé pendant que l'heure était prise, serveur d'e-mails en panne : le paiement est confirmé quand même (24.10.2026). */
    public function test_une_panne_de_l_accuse_ne_defait_pas_la_confirmation_du_paiement(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        $jeton = $this->deposer()->json('data.jeton');
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->mardi->utc(),
            'ends_at' => $this->mardi->addHour()->utc(),
        ]);
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP indisponible'));

        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk()->assertJsonPath('data.statut', 'a_traiter');
        $this->assertNotNull($demande->refresh()->acompte_paye_le);
    }

    /** Les paiements de la clinique fermés au moment d'ouvrir l'acompte : aucune demande à payer ne reste (24.10.2026). */
    public function test_un_acompte_qui_ne_peut_s_ouvrir_ne_laisse_pas_de_demande(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        $this->stripe->erreurs['creerIntentionEnLigne'] = ValidationException::withMessages(['paiement' => 'Les paiements en ligne de cette clinique sont fermés.']);

        $this->deposer()->assertUnprocessable()->assertJsonValidationErrors('paiement');
        $this->assertSame(0, DemandeRendezVous::withoutGlobalScopes()->count());
    }

    /** Rendu en partie chez Stripe : le reste est encore en jeu, l'annuler demande toujours « Rembourser » ; avec le droit, le reste est rendu (24.10.2026). */
    public function test_un_acompte_rendu_en_partie_reste_en_jeu(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonVeto] = $this->employeAvecJeton($this->clinique);
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        app(WebhooksStripe::class)->traiter([
            'id' => 'evt_en_jeu', 'type' => 'charge.refunded',
            'objet' => ['payment_intent' => $demande->acompte_intention_id, 'refunded' => false, 'amount_refunded' => 1000],
            'compte' => $demande->acompte_stripe_compte_id,
        ]);

        $this->assertContains('acompte', $this->drapeaux($demande->rendez_vous_id));
        $this->postJson("/api/v1/rendez-vous/{$demande->rendez_vous_id}/annuler", [], $this->enTetes($jetonVeto))->assertForbidden();
        $this->assertSame('prevu', RendezVous::withoutGlobalScopes()->find($demande->rendez_vous_id)->status->value);
        $this->assertSame([], $this->stripe->appelsDe('rembourser'));

        $this->postJson("/api/v1/rendez-vous/{$demande->rendez_vous_id}/annuler", ['acompte' => 'complet'], $this->enTetes($jetonAdmin))->assertOk();
        $this->assertSame(2000, $this->stripe->montantsRembourses[$demande->acompte_intention_id] ?? null);
        $this->assertSame(3000, $demande->refresh()->acompte_rembourse_centimes);
    }

    /** Annulation refusée (rendez-vous maintenu), rendu partiel chez Stripe, puis annulé par la clinique : le reste est rendu (24.10.2026). */
    public function test_le_reste_est_rendu_a_l_annulation_apres_un_maintien(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $this->postJson("/api/v1/espace/rendez-vous/{$demande->rendez_vous_id}/demander-annulation", [], $this->enTetes($jetonProprietaire))->assertOk();
        $this->postJson("/api/v1/demandes/{$demande->id}/annulation", ['decision' => 'refusee'], $this->enTetes($jetonAdmin))->assertOk();
        app(WebhooksStripe::class)->traiter([
            'id' => 'evt_maintien', 'type' => 'charge.refunded',
            'objet' => ['payment_intent' => $demande->acompte_intention_id, 'refunded' => false, 'amount_refunded' => 1000],
            'compte' => $demande->acompte_stripe_compte_id,
        ]);

        $this->postJson("/api/v1/rendez-vous/{$demande->rendez_vous_id}/annuler", ['acompte' => 'complet'], $this->enTetes($jetonAdmin))->assertOk();

        $this->assertSame(2000, $this->stripe->montantsRembourses[$demande->acompte_intention_id] ?? null);
    }

    /** Une décision prise dans l'app (garder l'acompte, ou rendre moins les frais à l'annulation par la clinique) ne se rejoue jamais (24.10.2026). */
    public function test_une_decision_d_acompte_ne_se_rejoue_pas(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $acomptes = app(AcomptesRendezVous::class);

        // Gardé, à la demande du propriétaire
        $garde = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $this->postJson("/api/v1/espace/rendez-vous/{$garde->rendez_vous_id}/demander-annulation", [], $this->enTetes($jetonProprietaire))->assertOk();
        $this->postJson("/api/v1/demandes/{$garde->id}/annulation", ['decision' => 'sans'], $this->enTetes($jetonAdmin))->assertOk();
        $this->assertFalse($acomptes->rembourser($garde->refresh()));
        $this->assertFalse($garde->acompteEnJeu());

        // Rendu moins les frais, la clinique ayant annulé d'elle-même
        $moinsLesFrais = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi->addWeek());
        $this->postJson("/api/v1/rendez-vous/{$moinsLesFrais->rendez_vous_id}/annuler", ['acompte' => 'hors_frais'], $this->enTetes($jetonAdmin))->assertOk();
        $rendu = $this->stripe->montantsRembourses[$moinsLesFrais->acompte_intention_id];
        $this->assertFalse($acomptes->rembourser($moinsLesFrais->refresh()));
        $this->assertSame($rendu, $this->stripe->montantsRembourses[$moinsLesFrais->acompte_intention_id]);
    }

    /** Une demande ouverte dont l'acompte est rendu en partie ne se supprime pas : la refuser rend le reste (24.10.2026). */
    public function test_une_demande_dont_l_acompte_est_en_partie_rendu_ne_se_supprime_pas(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $jeton = $this->deposer()->json('data.jeton');
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->mardi->utc(),
            'ends_at' => $this->mardi->addHour()->utc(),
        ]);
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk()->assertJsonPath('data.statut', 'a_traiter');
        app(WebhooksStripe::class)->traiter([
            'id' => 'evt_suppression', 'type' => 'charge.refunded',
            'objet' => ['payment_intent' => $demande->acompte_intention_id, 'refunded' => false, 'amount_refunded' => 1000],
            'compte' => $demande->acompte_stripe_compte_id,
        ]);

        $this->deleteJson("/api/v1/demandes/{$demande->id}", [], $this->enTetes($jetonAdmin))->assertUnprocessable()->assertJsonValidationErrors('demande');
        $this->assertNotNull(DemandeRendezVous::withoutGlobalScopes()->find($demande->id));
    }

    /** L'accusé du paiement (heure prise pendant le paiement) part après l'enregistrement : une confirmation défaite n'envoie rien (24.10.2026). */
    public function test_l_accuse_du_paiement_part_apres_l_enregistrement(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        $jeton = $this->deposer()->json('data.jeton');
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->mardi->utc(),
            'ends_at' => $this->mardi->addHour()->utc(),
        ]);
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        Mail::fake();

        try {
            DB::transaction(function () use ($jeton): void {
                $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk();
                throw new \RuntimeException('Suite impossible');
            });
        } catch (\RuntimeException) {
        }

        Mail::assertNothingOutgoing();
        $this->assertNull($demande->refresh()->acompte_paye_le);
    }

    /** Un acompte gardé par décision (« sans ») laisse sa trace : la demande ne se supprime pas (24.10.2026). */
    public function test_une_demande_a_l_acompte_garde_ne_se_supprime_pas(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $this->postJson("/api/v1/espace/rendez-vous/{$demande->rendez_vous_id}/demander-annulation", [], $this->enTetes($jetonProprietaire))->assertOk();
        $this->postJson("/api/v1/demandes/{$demande->id}/annulation", ['decision' => 'sans'], $this->enTetes($jetonAdmin))->assertOk();

        $this->deleteJson("/api/v1/demandes/{$demande->id}", [], $this->enTetes($jetonAdmin))->assertUnprocessable()->assertJsonValidationErrors('demande');
        $this->assertNotNull(DemandeRendezVous::withoutGlobalScopes()->find($demande->id));
    }

    /** Annulation par la clinique qui échoue en route : aucune décision d'acompte notée, l'acompte reste en jeu (24.10.2026). */
    public function test_une_annulation_qui_echoue_ne_note_pas_de_decision(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        RendezVous::saving(function (RendezVous $rendezVous): void {
            if ($rendezVous->isDirty('status') && $rendezVous->status === StatutRendezVous::Annule) {
                throw new \RuntimeException('Base indisponible');
            }
        });

        $this->postJson("/api/v1/rendez-vous/{$demande->rendez_vous_id}/annuler", ['acompte' => 'sans'], $this->enTetes($jetonAdmin))->assertServerError();

        $demande->refresh();
        $this->assertNull($demande->annulation_decision);
        $this->assertTrue($demande->acompteEnJeu());
        $this->assertContains('acompte', $this->drapeaux($demande->rendez_vous_id));
    }

    /** Une demande en ligne sans acompte, annulée par la clinique : aucune décision d'acompte notée (24.10.2026). */
    public function test_sans_acompte_aucune_decision_n_est_notee(): void
    {
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $this->postJson('/api/v1/espace/demandes', [
            'clinique_id' => $this->clinique->id, 'patient_name' => 'Louison', 'espece' => 'CH', 'motif' => 'Vaccin annuel',
            'mode' => 'place', 'debut_souhaite' => $this->mardi->toIso8601String(),
        ], $this->enTetes($jetonProprietaire))->assertCreated()->assertJsonPath('data.statut', 'acceptee');
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();

        $this->postJson("/api/v1/rendez-vous/{$demande->rendez_vous_id}/annuler", [], $this->enTetes($jetonAdmin))->assertOk();

        $this->assertNull($demande->refresh()->annulation_decision);
    }

    /** La décision sur une demande d'annulation est enregistrée même si l'e-mail au propriétaire ne part pas (24.10.2026). */
    public function test_une_panne_d_e_mail_ne_fait_pas_echouer_la_decision(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $this->postJson("/api/v1/espace/rendez-vous/{$demande->rendez_vous_id}/demander-annulation", [], $this->enTetes($jetonProprietaire))->assertOk();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP indisponible'));

        $this->postJson("/api/v1/demandes/{$demande->id}/annulation", ['decision' => 'refusee'], $this->enTetes($jetonAdmin))->assertOk();
        $this->assertSame('refusee', $demande->refresh()->annulation_decision->value);
    }

    /** Absence du praticien : le rendu et l'annulation ensemble ; si l'annulation échoue, rien n'est noté, l'absence est enregistrée et le rendez-vous reste à annuler à la main (24.10.2026). */
    public function test_une_absence_qui_echoue_ne_note_pas_de_decision(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $this->postJson("/api/v1/espace/rendez-vous/{$demande->rendez_vous_id}/demander-annulation", [], $this->enTetes($jetonProprietaire))->assertOk();
        RendezVous::saving(function (RendezVous $rendezVous): void {
            if ($rendezVous->isDirty('status') && $rendezVous->status === StatutRendezVous::Annule) {
                throw new \RuntimeException('Base indisponible');
            }
        });

        // L'absence est enregistrée, ce rendez-vous reste au planning, à annuler à la main
        $this->postJson('/api/v1/absences', [
            'employe_id' => $this->ligne->employe_id, 'type' => 'maladie', 'statut' => 'ok',
            'debut_le' => $this->mardi->toDateString(), 'fin_le' => $this->mardi->toDateString(),
        ], $this->enTetes($jetonAdmin))->assertCreated()->assertJsonPath('meta.rendez_vous_annules', 0)->assertJsonPath('meta.rendez_vous_restants', 1);

        $demande->refresh();
        $this->assertNull($demande->annulation_decision);
        // Le rendu noté est défait avec l'annulation (le webhook de Stripe le notera s'il est parti)
        $this->assertNull($demande->acompte_rembourse_le);
        $this->assertTrue($demande->acompteEnJeu());
        $this->assertSame('prevu', RendezVous::withoutGlobalScopes()->find($demande->rendez_vous_id)->status->value);
    }

    /** Absence sur deux rendez-vous : le premier échoue et reste à annuler à la main, le suivant est annulé et son acompte rendu (24.10.2026). */
    public function test_une_absence_continue_apres_un_rendez_vous_en_echec(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $premier = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $suivant = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi->addHour());
        RendezVous::saving(function (RendezVous $rendezVous) use ($premier): void {
            if ($rendezVous->id === $premier->rendez_vous_id && $rendezVous->isDirty('status') && $rendezVous->status === StatutRendezVous::Annule) {
                throw new \RuntimeException('Base indisponible');
            }
        });

        $this->postJson('/api/v1/absences', [
            'employe_id' => $this->ligne->employe_id, 'type' => 'maladie', 'statut' => 'ok',
            'debut_le' => $this->mardi->toDateString(), 'fin_le' => $this->mardi->toDateString(),
        ], $this->enTetes($jetonAdmin))->assertCreated()->assertJsonPath('meta.rendez_vous_annules', 1)->assertJsonPath('meta.rendez_vous_restants', 1);

        $this->assertSame('prevu', RendezVous::withoutGlobalScopes()->find($premier->rendez_vous_id)->status->value);
        $premier->refresh();
        $this->assertNull($premier->annulation_decision);
        $this->assertNull($premier->acompte_rembourse_le);
        $this->assertSame('annule', RendezVous::withoutGlobalScopes()->find($suivant->rendez_vous_id)->status->value);
        $this->assertSame(3000, $suivant->refresh()->acompte_rembourse_centimes);
    }

    /** Un créneau tampon dont l'annulation échoue ne compte pas parmi les rendez-vous à annuler à la main (24.10.2026). */
    public function test_un_tampon_en_echec_ne_compte_pas_dans_les_restants(): void
    {
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        $tampon = RendezVous::factory()->tampon()->create([
            'clinique_id' => $this->clinique->id, 'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->mardi->utc(), 'ends_at' => $this->mardi->addMinutes(30)->utc(),
        ]);
        RendezVous::saving(function (RendezVous $rendezVous) use ($tampon): void {
            if ($rendezVous->id === $tampon->id && $rendezVous->isDirty('status')) {
                throw new \RuntimeException('Base indisponible');
            }
        });

        $this->postJson('/api/v1/absences', [
            'employe_id' => $this->ligne->employe_id, 'type' => 'maladie', 'statut' => 'ok',
            'debut_le' => $this->mardi->toDateString(), 'fin_le' => $this->mardi->toDateString(),
        ], $this->enTetes($jetonAdmin))->assertCreated()->assertJsonPath('meta.rendez_vous_restants', 0);
        $this->assertSame('prevu', $tampon->refresh()->status->value);
    }

    /** Un acompte contesté à la banque (litige ouvert, gagné ou perdu) laisse sa trace : la demande ne se supprime pas (24.10.2026). */
    public function test_une_demande_a_l_acompte_conteste_ne_se_supprime_pas(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonAdmin] = $this->administrateurAvecJeton($this->clinique);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $webhook = fn (string $id, string $type, array $objet) => app(WebhooksStripe::class)->traiter(['id' => $id, 'type' => $type, 'objet' => ['payment_intent' => $demande->acompte_intention_id, ...$objet], 'compte' => $demande->acompte_stripe_compte_id]);
        $supprimer = fn () => $this->deleteJson("/api/v1/demandes/{$demande->id}", [], $this->enTetes($jetonAdmin));

        // Rendu en entier d'abord : sans litige, plus rien ne la retient
        $webhook('evt_trace_0', 'charge.refunded', ['refunded' => true, 'amount_refunded' => 3000]);
        $this->assertSame(0, $demande->refresh()->acompteRestantCentimes());
        // Litige ouvert, puis gagné, puis perdu (sur une autre demande) : elle reste
        $webhook('evt_trace_1', 'charge.dispute.created', []);
        $supprimer()->assertUnprocessable()->assertJsonValidationErrors('demande');
        $webhook('evt_trace_2', 'charge.dispute.closed', ['status' => 'won']);
        $supprimer()->assertUnprocessable()->assertJsonValidationErrors('demande');

        $autre = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi->addWeek());
        app(WebhooksStripe::class)->traiter(['id' => 'evt_trace_3', 'type' => 'charge.dispute.created', 'objet' => ['payment_intent' => $autre->acompte_intention_id], 'compte' => $autre->acompte_stripe_compte_id]);
        app(WebhooksStripe::class)->traiter(['id' => 'evt_trace_4', 'type' => 'charge.dispute.closed', 'objet' => ['payment_intent' => $autre->acompte_intention_id, 'status' => 'lost'], 'compte' => $autre->acompte_stripe_compte_id]);
        $this->assertSame(0, $autre->refresh()->acompteRestantCentimes());
        $this->deleteJson("/api/v1/demandes/{$autre->id}", [], $this->enTetes($jetonAdmin))->assertUnprocessable()->assertJsonValidationErrors('demande');
    }

    /** Litige clos : gagné ou « warning_closed » garde l'acompte ; un événement d'un autre compte Stripe est ignoré. */
    public function test_un_litige_d_acompte_gagne_ou_clos_sans_suite_garde_l_acompte(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $demande = $this->reserverAvecAcompte($jetonProprietaire, $this->mardi);
        $webhook = fn (string $id, string $type, array $objet, ?string $compte = null) => app(WebhooksStripe::class)->traiter([
            'id' => $id, 'type' => $type, 'objet' => $objet, 'compte' => $compte ?? $demande->acompte_stripe_compte_id,
        ]);

        foreach (['won', 'warning_closed'] as $i => $statut) {
            $webhook("evt_ouvert_{$i}", 'charge.dispute.created', ['payment_intent' => $demande->acompte_intention_id]);
            $webhook("evt_clos_{$i}", 'charge.dispute.closed', ['payment_intent' => $demande->acompte_intention_id, 'status' => $statut]);
            $demande->refresh();
            $this->assertSame('gagne', $demande->acompte_litige, $statut);
            $this->assertNull($demande->acompte_rembourse_le, $statut);
            $this->assertSame(30.0, $demande->acompteRetenu(), $statut);
        }

        // Un remboursement ou un litige perdu annoncé par un autre compte : rien ne bouge
        $webhook('evt_autre_r', 'charge.refunded', ['payment_intent' => $demande->acompte_intention_id, 'refunded' => true, 'amount_refunded' => 3000], 'acct_intrus');
        $webhook('evt_autre_l', 'charge.dispute.closed', ['payment_intent' => $demande->acompte_intention_id, 'status' => 'lost'], 'acct_intrus');
        $demande->refresh();
        $this->assertNull($demande->acompte_rembourse_le);
        $this->assertSame('gagne', $demande->acompte_litige);
    }

    public function test_un_acompte_que_stripe_refuse_de_rendre_ne_perd_rien_on_reessaie(): void
    {
        $this->clinique->update(['acompte_centimes' => 3000]);
        ['jeton' => $jetonClinique] = $this->administrateurAvecJeton($this->clinique);
        $entetes = $this->enTetes($jetonClinique);

        // Annulation par la clinique : le rendu échoue, rien n'est annulé
        $jeton = $this->deposer()->json('data.jeton');
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk();
        $rendezVousId = $demande->refresh()->rendez_vous_id;

        $this->stripe->erreurs['rembourser'] = new ErreurPaiement(ErreurPaiement::GENERIQUE, 'balance_insufficient');
        $this->postJson("/api/v1/rendez-vous/{$rendezVousId}/annuler", [], $entetes)
            ->assertUnprocessable()
            ->assertJsonPath('errors.paiement.0', ErreurPaiement::GENERIQUE);
        $this->assertNotSame('annule', RendezVous::withoutGlobalScopes()->findOrFail($rendezVousId)->status->value);
        $this->assertNull($demande->refresh()->acompte_rembourse_le);

        // Stripe répond de nouveau : annulé et rendu, une seule fois
        unset($this->stripe->erreurs['rembourser']);
        $this->postJson("/api/v1/rendez-vous/{$rendezVousId}/annuler", [], $entetes)->assertOk();
        $this->assertSame('annule', RendezVous::withoutGlobalScopes()->findOrFail($rendezVousId)->status->value);
        $this->assertCount(1, array_keys($this->stripe->remboursements, $demande->acompte_intention_id, true));

        // Refus d'une demande payée (heure prise pendant le paiement) : même chose
        $autre = $this->deposer(['debut_souhaite' => $this->mardi->addHours(4)->toIso8601String()]);
        $enAttente = DemandeRendezVous::withoutGlobalScopes()->latest('id')->first();
        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->mardi->addHours(4)->utc(),
            'ends_at' => $this->mardi->addHours(5)->utc(),
        ]);
        $this->stripe->conclure($enAttente->acompte_intention_id, 'succeeded');
        $this->postJson('/api/v1/public/demandes/'.$autre->json('data.jeton').'/paiement')->assertOk()->assertJsonPath('data.statut', 'a_traiter');

        $this->stripe->erreurs['rembourser'] = new ErreurPaiement(ErreurPaiement::GENERIQUE, 'balance_insufficient');
        $this->postJson("/api/v1/demandes/{$enAttente->id}/refuser", ['motif' => 'agenda'], $entetes)->assertUnprocessable();
        $this->assertSame('a_traiter', $enAttente->refresh()->statut->value);
        Mail::assertNotSent(DemandeRendezVousRefusee::class);

        unset($this->stripe->erreurs['rembourser']);
        $this->postJson("/api/v1/demandes/{$enAttente->id}/refuser", ['motif' => 'agenda'], $entetes)->assertOk();
        $this->assertSame('refusee', $enAttente->refresh()->statut->value);
        $this->assertCount(1, array_keys($this->stripe->remboursements, $enAttente->acompte_intention_id, true));
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

        $demande = DemandeRendezVous::withoutGlobalScopes()->where('jeton', DemandeRendezVous::empreinte($jeton))->sole();
        $this->stripe->conclure($demande->acompte_intention_id, 'succeeded');
        $this->postJson("/api/v1/public/demandes/{$jeton}/paiement")->assertOk()->assertJsonPath('data.statut', 'acceptee');

        return $demande->refresh();
    }
}
