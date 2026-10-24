<?php

namespace Tests\Feature\Planning;

use App\Mail\DemandeRendezVousAcceptee;
use App\Mail\DemandeRendezVousEnvoyee;
use App\Mail\DemandeRendezVousProposee;
use App\Mail\DemandeRendezVousRefusee;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Demandes de rendez-vous en ligne, de bout en bout : la clinique
 * accepte l'heure demandée, en propose d'autres ou refuse ; le
 * propriétaire est prévenu par e-mail et suit sa demande depuis son
 * espace (ou le lien sans compte), où il choisit l'horaire proposé.
 */
class DemandesEnLigneTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private LignePlanning $ligne;

    private string $jeton;

    /** Un mardi à venir, 10 h à la clinique : ouvert, hors pause */
    private CarbonImmutable $mardi;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->clinique = Clinique::factory()->create(['fuseau_horaire' => 'Europe/Zurich', 'tel' => '021 600 00 00']);
        HoraireOuverture::factory()->create(['clinique_id' => $this->clinique->id]);
        $this->ligne = LignePlanning::factory()->create(['clinique_id' => $this->clinique->id]);
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->mardi = CarbonImmutable::now('Europe/Zurich')->next(CarbonImmutable::TUESDAY)->setTime(10, 0);
    }

    private function demande(array $attributs = []): DemandeRendezVous
    {
        return DemandeRendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'email' => 'pineau@exemple.ch',
            'debut_souhaite' => $this->mardi->utc(),
            'duree_minutes' => 20,
            'jeton' => Str::random(48),
            ...$attributs,
        ]);
    }

    public function test_accepter_prend_l_heure_demandee_sur_une_ligne_libre_et_previent_le_proprietaire(): void
    {
        $demande = $this->demande();

        $reponse = $this->postJson("/api/v1/demandes/{$demande->id}/placer", [], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.ligne_planning_id', $this->ligne->id);

        $this->assertTrue(CarbonImmutable::parse($reponse->json('data.starts_at'))->equalTo($this->mardi));
        $this->assertSame('acceptee', $demande->refresh()->statut->value);
        $this->assertSame($reponse->json('data.id'), $demande->rendez_vous_id);
        Mail::assertSent(DemandeRendezVousAcceptee::class, fn ($mail) => $mail->hasTo('pineau@exemple.ch'));

        // Une seule réponse par demande
        $this->postJson("/api/v1/demandes/{$demande->id}/refuser", ['motif' => 'agenda'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statut');
    }

    public function test_accepter_une_heure_deja_prise_ou_une_demande_sans_heure_est_refuse(): void
    {
        RendezVous::factory()->create([
            'clinique_id' => $this->clinique->id,
            'ligne_planning_id' => $this->ligne->id,
            'starts_at' => $this->mardi->utc(),
            'ends_at' => $this->mardi->addMinutes(30)->utc(),
        ]);
        $prise = $this->demande();
        $sansHeure = $this->demande(['debut_souhaite' => null]);

        $this->postJson("/api/v1/demandes/{$prise->id}/placer", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
        $this->postJson("/api/v1/demandes/{$sansHeure->id}/placer", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertSame('a_traiter', $prise->refresh()->statut->value);
        Mail::assertNothingSent();
    }

    public function test_accepter_a_une_autre_heure_choisit_une_ligne_libre(): void
    {
        $demande = $this->demande();
        $autre = $this->mardi->addHours(5);

        $reponse = $this->postJson("/api/v1/demandes/{$demande->id}/placer", ['starts_at' => $autre->toIso8601String()], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.ligne_planning_id', $this->ligne->id);

        $this->assertTrue(CarbonImmutable::parse($reponse->json('data.starts_at'))->equalTo($autre));
    }

    public function test_proposer_d_autres_horaires_libres_les_ecrit_au_proprietaire(): void
    {
        $demande = $this->demande();
        $horaires = [$this->mardi->addHour(), $this->mardi->addDay()->setTime(9, 0)];

        $this->postJson("/api/v1/demandes/{$demande->id}/proposer", [
            'creneaux' => array_map(fn (CarbonImmutable $h): string => $h->toIso8601String(), $horaires),
            'message' => 'Le Dr Meyer vous verra.',
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'proposee')
            ->assertJsonCount(2, 'data.creneaux_proposes');

        Mail::assertSent(DemandeRendezVousProposee::class, fn ($mail) => $mail->hasTo('pineau@exemple.ch'));

        // Une heure fermée (dimanche) ne se propose pas
        $autre = $this->demande();
        $this->postJson("/api/v1/demandes/{$autre->id}/proposer", [
            'creneaux' => [$this->mardi->next(CarbonImmutable::SUNDAY)->setTime(10, 0)->toIso8601String()],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('creneaux.0');
    }

    /** Sans compte : le lien reçu au dépôt reste le bon après la proposition, et celui du nouvel e-mail aussi. */
    public function test_proposer_garde_le_lien_de_la_demande_sans_compte(): void
    {
        $demande = $this->demande();
        $lien = $demande->jetonClair();

        $this->postJson("/api/v1/demandes/{$demande->id}/proposer", [
            'creneaux' => [$this->mardi->addHour()->toIso8601String()],
        ], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame($lien, $demande->refresh()->jetonClair());
        $this->assertSame(DemandeRendezVous::empreinte($lien), $demande->getRawOriginal('jeton'));
        Mail::assertSent(DemandeRendezVousProposee::class, fn ($mail) => str_contains($mail->demande->lienDeSuivi(), '/demande/'.$lien));

        $this->getJson("/api/v1/public/demandes/{$lien}")
            ->assertOk()
            ->assertJsonPath('data.statut', 'proposee');
        $this->postJson("/api/v1/public/demandes/{$lien}/choisir", ['starts_at' => $this->mardi->addHour()->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.statut', 'acceptee');
    }

    public function test_refuser_garde_le_motif_et_previent_le_proprietaire(): void
    {
        $demande = $this->demande();

        $this->postJson("/api/v1/demandes/{$demande->id}/refuser", [
            'motif' => 'agenda',
            'message' => 'Essayez la clinique du Lac.',
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'refusee')
            ->assertJsonPath('data.motif_refus', 'agenda');

        Mail::assertSent(DemandeRendezVousRefusee::class, fn ($mail) => $mail->hasTo('pineau@exemple.ch'));
    }

    public function test_la_file_montre_les_demandes_ouvertes_et_les_traitees_du_jour(): void
    {
        $ouverte = $this->demande();
        $proposee = $this->demande(['statut' => 'proposee', 'traitee_le' => now()->subDays(2)]);
        $traiteeAujourdhui = $this->demande(['statut' => 'refusee', 'traitee_le' => now()]);
        $this->demande(['statut' => 'acceptee', 'traitee_le' => now()->subDays(2)]);

        $ids = collect($this->getJson('/api/v1/demandes', $this->enTetes($this->jeton))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$ouverte->id, $proposee->id, $traiteeAujourdhui->id], $ids);
    }

    public function test_par_defaut_la_demande_reserve_le_rendez_vous_aussitot(): void
    {
        ['user' => $user, 'jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();

        $this->postJson('/api/v1/espace/demandes', [
            'clinique_id' => $this->clinique->id,
            'patient_name' => 'Louison',
            'espece' => 'RG',
            'motif' => 'Vaccin annuel',
            'mode' => 'place',
            'debut_souhaite' => $this->mardi->toIso8601String(),
        ], $this->enTetes($jetonProprietaire))
            ->assertCreated()
            ->assertJsonPath('data.statut', 'acceptee');

        $rendezVous = RendezVous::withoutGlobalScopes()->sole();
        $this->assertTrue($rendezVous->starts_at->equalTo($this->mardi));
        $this->assertSame($this->ligne->id, $rendezVous->ligne_planning_id);
        $this->assertSame('confirme', DemandeRendezVous::withoutGlobalScopes()->sole()->mode->value);
        Mail::assertSent(DemandeRendezVousAcceptee::class, fn ($mail) => $mail->hasTo($user->email));
        Mail::assertNotSent(DemandeRendezVousEnvoyee::class);

        // La même heure, prise entre-temps : refusée, rien n'est créé
        $this->postJson('/api/v1/espace/demandes', [
            'clinique_id' => $this->clinique->id,
            'patient_name' => 'Pacha',
            'espece' => 'CT',
            'motif' => 'Contrôle',
            'mode' => 'place',
            'debut_souhaite' => $this->mardi->toIso8601String(),
        ], $this->enTetes($jetonProprietaire))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('debut_souhaite');
        $this->assertSame(1, DemandeRendezVous::withoutGlobalScopes()->count());
    }

    public function test_le_proprietaire_suit_sa_demande_et_choisit_un_horaire_propose(): void
    {
        // La clinique valide chaque rendez-vous : la demande attend sa réponse
        $this->clinique->update(['rdv_a_valider' => true]);
        ['user' => $user, 'jeton' => $jetonProprietaire] = $this->proprietaireAvecJeton();
        $animal = Patient::factory()->create(['organisation_id' => null, 'client_id' => null, 'proprietaire_id' => $user->id, 'nom' => 'Louison']);

        // Déposée depuis l'espace : le compte, l'heure, l'accusé de réception
        $this->postJson('/api/v1/espace/demandes', [
            'clinique_id' => $this->clinique->id,
            'patient_id' => $animal->id,
            'motif' => 'Vaccin annuel',
            'mode' => 'place',
            'debut_souhaite' => $this->mardi->toIso8601String(),
        ], $this->enTetes($jetonProprietaire))->assertCreated();

        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        $this->assertSame($user->id, $demande->user_id);
        $this->assertTrue($demande->debut_souhaite->equalTo($this->mardi), $demande->debut_souhaite.' / '.$this->mardi->utc());
        Mail::assertSent(DemandeRendezVousEnvoyee::class, fn ($mail) => $mail->hasTo($user->email));

        // La clinique propose une autre heure
        $propose = $this->mardi->addHours(4);
        $this->postJson("/api/v1/demandes/{$demande->id}/proposer", ['creneaux' => [$propose->toIso8601String()]], $this->enTetes($this->jeton))->assertOk();

        $this->getJson('/api/v1/espace/demandes', $this->enTetes($jetonProprietaire))
            ->assertOk()
            ->assertJsonPath('data.0.statut', 'proposee')
            ->assertJsonPath('data.0.clinique.nom', $this->clinique->nom)
            ->assertJsonCount(1, 'data.0.creneaux_proposes');

        // Une heure qui n'a pas été proposée : refusée
        $this->postJson("/api/v1/espace/demandes/{$demande->id}/choisir", ['starts_at' => $this->mardi->toIso8601String()], $this->enTetes($jetonProprietaire))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => 'Cette heure ne fait pas partie des propositions.']);

        $this->postJson("/api/v1/espace/demandes/{$demande->id}/choisir", ['starts_at' => $propose->toIso8601String()], $this->enTetes($jetonProprietaire))
            ->assertOk()
            ->assertJsonPath('data.statut', 'acceptee');

        // Le rendez-vous apparaît dans « Mes rendez-vous », sans être une fiche de la clinique
        $rendezVous = RendezVous::withoutGlobalScopes()->sole();
        $this->assertNull($rendezVous->patient_id);
        $this->assertTrue($rendezVous->starts_at->equalTo($propose), $rendezVous->starts_at.' / '.$propose->utc());
        $this->getJson('/api/v1/espace/rendez-vous', $this->enTetes($jetonProprietaire))
            ->assertJsonPath('data.a_venir.0.id', $rendezVous->id);
        Mail::assertSent(DemandeRendezVousAcceptee::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_sans_compte_le_lien_de_l_e_mail_montre_la_demande_et_fait_choisir(): void
    {
        $demande = $this->demande(['statut' => 'proposee', 'creneaux_proposes' => [$this->mardi->addHour()->utc()->toIso8601String()]]);

        $this->getJson("/api/v1/public/demandes/{$demande->jetonClair()}")
            ->assertOk()
            ->assertJsonPath('data.statut', 'proposee')
            ->assertJsonPath('data.patient_name', $demande->patient_name);

        $this->postJson("/api/v1/public/demandes/{$demande->jetonClair()}/choisir", ['starts_at' => $this->mardi->addHour()->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.statut', 'acceptee');

        $this->getJson('/api/v1/public/demandes/'.Str::random(48))->assertNotFound();
        Mail::assertSent(DemandeRendezVousAcceptee::class);

        // En base, seulement l'empreinte du lien ; il ne sert plus 30 jours après l'heure
        $this->assertSame(64, strlen((string) $demande->refresh()->getRawOriginal('jeton')));
        $this->assertNotSame($demande->jetonClair(), $demande->getRawOriginal('jeton'));
        $this->travelTo($demande->debut_souhaite->addDays(31));
        $this->getJson("/api/v1/public/demandes/{$demande->jetonClair()}")->assertStatus(410);
    }
}
