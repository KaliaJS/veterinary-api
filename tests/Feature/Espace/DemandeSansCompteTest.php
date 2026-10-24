<?php

namespace Tests\Feature\Espace;

use App\Enums\MotifRefusDemande;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\HoraireOuverture;
use App\Models\LignePlanning;
use App\Paiements\PasserellePaiement;
use App\Services\CodeDemandePublique;
use App\Services\TraitementDemande;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\DeposeSansCompte;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserellePaiementFactice;
use Tests\TestCase;

/**
 * Rendez-vous sans compte : seulement chez une clinique qui l'accepte
 * (non par défaut), avec de quoi répondre au propriétaire ; la clinique
 * la voit dans sa file comme les autres demandes.
 */
class DemandeSansCompteTest extends TestCase
{
    use DeposeSansCompte;
    use EmetDesJetonsApi;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function demande(Clinique $clinique, array $champs = []): array
    {
        return [
            'clinique_id' => $clinique->id,
            // Une heure de la grille du calendrier, un jour ouvert
            'debut_souhaite' => CarbonImmutable::now('Europe/Zurich')->next(CarbonImmutable::TUESDAY)->setTime(10, 0)->toIso8601String(),
            'mode' => 'place',
            'patient_name' => 'Pacha',
            'espece' => 'CT',
            'motif' => 'Consultation',
            'nom_proprietaire' => 'Léa Rochat',
            'email' => 'Lea.Rochat@example.ch',
            'telephone' => '+41 79 123 45 67',
            'code' => '000000',
            ...$champs,
        ];
    }

    public function test_une_clinique_demande_un_compte_par_defaut(): void
    {
        $clinique = Clinique::factory()->create();

        $this->assertFalse($clinique->fresh()->rdv_sans_compte);
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique))
            ->assertUnprocessable()->assertJsonValidationErrors('clinique_id');
        $this->assertDatabaseCount('demandes_rendez_vous', 0);
    }

    public function test_la_clinique_qui_l_accepte_recoit_la_demande_avec_les_coordonnees(): void
    {
        // Elle valide chaque rendez-vous : la demande reste dans sa file
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['rdv_sans_compte' => true, 'rdv_a_valider' => true]);

        $this->getJson('/api/v1/public/cliniques')->assertOk()
            ->assertJsonPath('data.0.rdv_sans_compte', true);

        $code = $this->codeDeDemande($clinique->id, 'Lea.Rochat@example.ch');
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['urgence' => true, 'symptomes' => 'Boite depuis hier', 'code' => $code]))
            ->assertCreated()
            ->assertJsonPath('data.patient_name', 'Pacha')
            ->assertJsonPath('data.urgence', true);

        $this->assertDatabaseHas('demandes_rendez_vous', [
            'clinique_id' => $clinique->id,
            'client_id' => null,
            'patient_id' => null,
            'owner_name' => 'Léa Rochat',
            'email' => 'lea.rochat@example.ch',
            'telephone' => '+41 79 123 45 67',
            'urgence' => true,
            'symptomes' => 'Boite depuis hier',
        ]);

        // La clinique la voit dans sa file, avec de quoi répondre
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $this->getJson('/api/v1/demandes', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.0.email', 'lea.rochat@example.ch')
            ->assertJsonPath('data.0.telephone', '+41 79 123 45 67')
            ->assertJsonPath('data.0.urgence', true)
            ->assertJsonPath('data.0.symptomes', 'Boite depuis hier');
    }

    /** Un code ne sert qu'une fois, même renvoyé aussitôt (deux onglets, double clic). */
    public function test_un_code_ne_fait_qu_une_demande(): void
    {
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['rdv_sans_compte' => true, 'rdv_a_valider' => true]);
        $code = $this->codeDeDemande($clinique->id, 'lea.rochat@example.ch');

        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code]))->assertCreated();
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code]))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(1, DemandeRendezVous::withoutGlobalScopes()->count());
    }

    /** Deux envois au même instant : celui qui consomme le code en second est refusé, aucune seconde demande (24.10.2026). */
    public function test_deux_envois_simultanes_ne_font_qu_une_demande(): void
    {
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['rdv_sans_compte' => true, 'rdv_a_valider' => true]);
        $code = $this->codeDeDemande($clinique->id, 'lea.rochat@example.ch');

        // L'autre envoi a vérifié le même code et l'a consommé juste avant
        $hache = Cache::get('demande-publique:code:'.$clinique->id.':'.hash('sha256', 'lea.rochat@example.ch'));
        $this->assertIsString($hache);
        Cache::add('demande-publique:utilise:'.hash('sha256', $hache), true, now()->addMinutes(10));

        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code]))
            ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Ce code a déjà servi : demandez-en un nouveau.']);
        $this->assertSame(0, DemandeRendezVous::withoutGlobalScopes()->count());

        // Et le service lui-même : un seul envoi réserve le code ; libéré, il resservira
        $codes = app(CodeDemandePublique::class);
        $code = $this->codeDeDemande($clinique->id, 'paul@example.ch');
        $codes->verifier('paul@example.ch', $clinique, $code);
        $codes->verifier('paul@example.ch', $clinique, $code);
        $marque = $codes->reserver('paul@example.ch', $clinique);
        $this->assertNotNull($marque);
        $this->assertNull($codes->reserver('paul@example.ch', $clinique));
        $codes->liberer('paul@example.ch', $clinique, $marque);
        $this->assertNotNull($codes->reserver('paul@example.ch', $clinique));
    }

    /** L'heure choisie est refusée (prise entre-temps, hors grille) : le même code sert pour une autre heure (24.10.2026). */
    public function test_une_heure_refusee_ne_brule_pas_le_code(): void
    {
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['rdv_sans_compte' => true, 'rdv_a_valider' => true]);
        $code = $this->codeDeDemande($clinique->id, 'lea.rochat@example.ch');
        $horsGrille = CarbonImmutable::now('Europe/Zurich')->next(CarbonImmutable::TUESDAY)->setTime(10, 5)->toIso8601String();

        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code, 'debut_souhaite' => $horsGrille]))
            ->assertUnprocessable()->assertJsonValidationErrors('debut_souhaite');
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code]))->assertCreated();
        // Puis il a servi : plus une seconde fois
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code]))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(1, DemandeRendezVous::withoutGlobalScopes()->count());
    }

    /** Une panne de l'accusé de réception ne fait pas échouer la demande enregistrée, et son code ne resservira pas (24.10.2026). */
    public function test_une_panne_de_l_accuse_ne_double_pas_la_demande(): void
    {
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['rdv_sans_compte' => true, 'rdv_a_valider' => true]);
        $code = $this->codeDeDemande($clinique->id, 'lea.rochat@example.ch');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP indisponible'));

        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code]))->assertCreated();
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code]))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(1, DemandeRendezVous::withoutGlobalScopes()->count());
    }

    /** Une heure refusée rend son essai au bon code, pas ceux des faux codes tapés avant (24.10.2026). */
    public function test_une_heure_refusee_n_efface_pas_les_faux_codes_tapes_avant(): void
    {
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['rdv_sans_compte' => true, 'rdv_a_valider' => true]);
        $codes = app(CodeDemandePublique::class);
        $code = $this->codeDeDemande($clinique->id, 'paul@example.ch');
        $faux = fn () => rescue(fn () => $codes->verifier('paul@example.ch', $clinique, '000000'), report: false);

        foreach (range(1, 4) as $essai) {
            $faux();
        }
        // Le bon code, puis l'heure refusée : cet essai-là seul ne compte pas
        $codes->verifier('paul@example.ch', $clinique, $code);
        $codes->liberer('paul@example.ch', $clinique, (string) $codes->reserver('paul@example.ch', $clinique));
        // Un cinquième faux code : le code est effacé
        $faux();

        $this->expectException(ValidationException::class);
        $codes->verifier('paul@example.ch', $clinique, $code);
    }

    /** Réservation directe (sans validation) et serveur d'e-mails en panne : le rendez-vous est pris, le code ne resservira pas (24.10.2026). */
    public function test_une_panne_de_la_confirmation_ne_defait_pas_la_reservation_directe(): void
    {
        $clinique = $this->cliniqueQuiReserve([]);
        $code = $this->codeDeDemande($clinique->id, 'lea.rochat@example.ch');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP indisponible'));

        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code, 'debut_souhaite' => $this->dansTroisJours()]))->assertCreated();
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        $this->assertSame('acceptee', $demande->statut->value);
        $this->assertNotNull($demande->rendez_vous_id);

        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code, 'debut_souhaite' => $this->dansTroisJours(1)]))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(1, DemandeRendezVous::withoutGlobalScopes()->count());
    }

    /** Les e-mails de décision partent après l'enregistrement : une décision défaite n'envoie rien ; une panne d'e-mail ne défait rien (24.10.2026). */
    public function test_les_e_mails_de_decision_partent_apres_l_enregistrement(): void
    {
        $clinique = $this->cliniqueQuiReserve(['rdv_a_valider' => true]);
        ['jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $this->codeDeDemande($clinique->id, 'lea.rochat@example.ch'), 'debut_souhaite' => $this->dansTroisJours()]))->assertCreated();
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();

        // Refus défait par une erreur qui suit dans la même transaction : aucun e-mail
        Mail::fake();
        try {
            DB::transaction(function () use ($demande): void {
                app(TraitementDemande::class)->refuser($demande, MotifRefusDemande::AgendaComplet, null);
                throw new \RuntimeException('Suite impossible');
            });
        } catch (\RuntimeException) {
        }
        Mail::assertNothingOutgoing();
        $this->assertSame('a_traiter', $demande->refresh()->statut->value);

        // Serveur d'e-mails en panne : la proposition, puis le refus, restent enregistrés
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP indisponible'));
        $this->postJson("/api/v1/demandes/{$demande->id}/proposer", ['creneaux' => [$this->dansTroisJours(2)]], $this->enTetes($jeton))
            ->assertOk()->assertJsonPath('data.statut', 'proposee');
        $this->postJson("/api/v1/demandes/{$demande->id}/refuser", ['motif' => 'agenda'], $this->enTetes($jeton))
            ->assertOk()->assertJsonPath('data.statut', 'refusee');
    }

    /** Proposée ou acceptée (placée), puis défaite par une erreur dans la même transaction : aucun e-mail (24.10.2026). */
    public function test_une_proposition_ou_une_acceptation_defaite_n_envoie_rien(): void
    {
        $clinique = $this->cliniqueQuiReserve(['rdv_a_valider' => true]);
        ['jeton' => $jeton] = $this->administrateurAvecJeton($clinique);
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $this->codeDeDemande($clinique->id, 'lea.rochat@example.ch'), 'debut_souhaite' => $this->dansTroisJours()]))->assertCreated();
        $demande = DemandeRendezVous::withoutGlobalScopes()->sole();
        Mail::fake();
        $defaire = function (callable $geste): void {
            try {
                DB::transaction(function () use ($geste): void {
                    $geste();
                    throw new \RuntimeException('Suite impossible');
                });
            } catch (\RuntimeException) {
            }
        };

        $defaire(fn () => $this->postJson("/api/v1/demandes/{$demande->id}/proposer", ['creneaux' => [$this->dansTroisJours(2)]], $this->enTetes($jeton))->assertOk());
        $defaire(fn () => $this->postJson("/api/v1/demandes/{$demande->id}/placer", ['starts_at' => $this->dansTroisJours()], $this->enTetes($jeton))->assertSuccessful());

        Mail::assertNothingOutgoing();
        $this->assertSame('a_traiter', $demande->refresh()->statut->value);
    }

    /** Une clinique qui réserve directement, ouverte, avec une ligne au planning. */
    private function cliniqueQuiReserve(array $attributs): Clinique
    {
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['rdv_sans_compte' => true, 'rdv_a_valider' => false, 'fuseau_horaire' => 'Europe/Zurich', ...$attributs]);
        HoraireOuverture::factory()->create(['clinique_id' => $clinique->id]);
        LignePlanning::factory()->create(['clinique_id' => $clinique->id]);

        return $clinique;
    }

    /** Un mardi au moins trois jours plus tard, à 10 h (plus une heure par `$decalage`). */
    private function dansTroisJours(int $decalage = 0): string
    {
        return CarbonImmutable::now('Europe/Zurich')->addDays(2)->next(CarbonImmutable::TUESDAY)->setTime(10 + $decalage, 0)->toIso8601String();
    }

    /** Une autre erreur après l'enregistrement (prestataire de paiement) : la demande a pu être faite, le code ne resservira pas (24.10.2026). */
    public function test_une_erreur_imprevue_consomme_le_code(): void
    {
        $clinique = $this->cliniqueQuiReserve(['acompte_centimes' => 3000]);
        $stripe = new PasserellePaiementFactice;
        $stripe->erreurs['creerIntentionEnLigne'] = new \RuntimeException('Réponse illisible du prestataire');
        $this->app->instance(PasserellePaiement::class, $stripe);
        $code = $this->codeDeDemande($clinique->id, 'lea.rochat@example.ch');

        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code, 'debut_souhaite' => $this->dansTroisJours()]));
            $this->fail('L’erreur du prestataire aurait dû remonter.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Réponse illisible du prestataire', $e->getMessage());
        }
        $this->withExceptionHandling();

        unset($stripe->erreurs['creerIntentionEnLigne']);
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code, 'debut_souhaite' => $this->dansTroisJours(1)]))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    /** Un bon code et une heure refusée : l'essai ne compte pas parmi les cinq. */
    public function test_une_heure_refusee_ne_compte_pas_comme_un_essai(): void
    {
        $clinique = Clinique::factory()->dansLAnnuaire()->create(['rdv_sans_compte' => true, 'rdv_a_valider' => true]);
        $code = $this->codeDeDemande($clinique->id, 'lea.rochat@example.ch');
        $horsGrille = CarbonImmutable::now('Europe/Zurich')->next(CarbonImmutable::TUESDAY)->setTime(10, 5)->toIso8601String();

        // Cinq heures refusées : avant, le sixième envoi dépassait les cinq essais
        foreach (range(1, 5) as $essai) {
            $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code, 'debut_souhaite' => $horsGrille]))
                ->assertUnprocessable()->assertJsonValidationErrors('debut_souhaite');
        }
        // Une minute plus tard (limite de cinq envois par minute), le même code sert
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['code' => $code]))->assertCreated();
    }

    public function test_sans_compte_il_faut_de_quoi_repondre(): void
    {
        $clinique = Clinique::factory()->create(['rdv_sans_compte' => true]);

        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['email' => '', 'telephone' => '12', 'nom_proprietaire' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'telephone', 'nom_proprietaire']);
        $this->postJson('/api/v1/public/demandes', $this->demande($clinique, ['urgence' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('symptomes');
    }

    public function test_hors_de_l_annuaire_une_clinique_ne_parait_que_demandee_par_son_id(): void
    {
        $absente = Clinique::factory()->create(['nom' => 'Sans photo']);
        $presente = Clinique::factory()->dansLAnnuaire()->create();

        $ids = collect($this->getJson('/api/v1/public/cliniques')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($presente->id, $ids);
        $this->assertNotContains($absente->id, $ids);

        // Le site de la clinique la demande par son id, pour ses créneaux
        $this->getJson('/api/v1/public/cliniques?cliniques='.$absente->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $absente->id)
            ->assertJsonPath('data.0.dans_annuaire', false)
            ->assertJsonMissingPath('data.0.creneaux.0.ligne_planning_id');

        // Un paramètre en tableau : requête mal formée, pas une panne
        $this->getJson('/api/v1/public/cliniques?cliniques[]=1')->assertUnprocessable()->assertJsonPath('message', 'Un paramètre de la requête n’a pas la bonne forme.');
    }
}
