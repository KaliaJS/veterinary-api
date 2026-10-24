<?php

namespace Tests\Feature\Espace;

use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Services\CodeDemandePublique;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\DeposeSansCompte;
use Tests\Concerns\EmetDesJetonsApi;
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
