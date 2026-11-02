<?php

namespace Tests\Feature\Patients;

use App\Auth\ContexteClinique;
use App\Auth\EmetteurJetons;
use App\Enums\StatutDemandeRattachement;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DemandeRattachement;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Un animal, un seul compte (décision de Jim, 02.11.2026) : le compte de
 * sa fiche à la clinique et celui qui l'a réclamé par sa puce sont le même,
 * ou l'un des deux manque. Chaque test suit une case de notes/todo.md.
 */
class OneAccountPerPetTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const PUCE = '756 098 100 234 567';

    /** Félix, sur la fiche « Rochat » de la clinique ; la fiche a ou non un compte */
    private function felix(Clinique $clinique, ?User $compteDeLaFiche = null): Patient
    {
        $fiche = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'nom' => 'Rochat', 'user_id' => $compteDeLaFiche?->id]);

        return Patient::factory()->create(['organisation_id' => $fiche->organisation_id, 'client_id' => $fiche->id, 'nom' => 'Félix', 'puce' => self::PUCE]);
    }

    public function test_reclamer_par_la_puce_seulement_si_la_fiche_n_a_pas_encore_de_compte(): void
    {
        $clinique = Clinique::factory()->create();
        $lea = User::factory()->create();
        $this->felix($clinique, $lea);
        ['jeton' => $jetonMarc] = $this->proprietaireAvecJeton();

        $this->postJson('/api/v1/espace/animaux/reclamer', ['puce' => self::PUCE, 'nom_proprietaire' => 'Rochat'], $this->enTetes($jetonMarc))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['puce' => 'Cet animal ne peut pas être ajouté à votre espace : la puce ou le nom ne correspondent pas, ou il est déjà dans l’espace d’un autre compte. Vérifiez-les, ou appelez la clinique qui le suit.']);
        $this->assertSame(0, DemandeRattachement::withoutGlobalScopes()->count());

        // Le compte de la fiche lui-même : l'animal est déjà chez lui
        $jetonLea = $this->jetonSansClinique($lea);
        $this->postJson('/api/v1/espace/animaux/reclamer', ['puce' => self::PUCE, 'nom_proprietaire' => 'Rochat'], $this->enTetes($jetonLea))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['puce' => 'Cet animal est déjà dans votre espace.']);
    }

    public function test_la_clinique_ne_peut_pas_accepter_une_reclamation_quand_la_fiche_a_deja_un_autre_compte(): void
    {
        $clinique = Clinique::factory()->create();
        $felix = $this->felix($clinique);
        $marc = User::factory()->create();
        // La réclamation est partie quand la fiche n'avait pas de compte ; depuis, Léa a reçu le sien
        $demande = DemandeRattachement::withoutGlobalScopes()->create(['organisation_id' => $felix->organisation_id, 'patient_id' => $felix->id, 'user_id' => $marc->id, 'puce_saisie' => self::PUCE, 'statut' => StatutDemandeRattachement::Attente]);
        Client::withoutGlobalScopes()->whereKey($felix->client_id)->update(['user_id' => User::factory()->create()->id]);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);

        $this->postJson("/api/v1/patients/rattachements/{$demande->id}/accepter", [], $this->enTetes($jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['demande' => 'Félix est déjà dans l’espace d’un autre compte, celui de sa fiche. Un animal n’appartient qu’à un seul compte.']);
        $this->assertNull($felix->refresh()->proprietaire_id);
    }

    public function test_ouvrir_l_espace_d_une_fiche_est_refuse_si_un_de_ses_animaux_appartient_a_un_autre_compte(): void
    {
        $clinique = Clinique::factory()->create();
        $felix = $this->felix($clinique);
        $marc = User::factory()->create();
        $felix->forceFill(['proprietaire_id' => $marc->id])->save();
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);

        $comptes = User::query()->count();
        $this->postJson("/api/v1/clients/{$felix->client_id}/compte", ['email' => 'lea@exemple.ch'], $this->enTetes($jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client' => 'Félix est déjà dans l’espace d’un autre compte (réclamé par sa puce). Un animal n’appartient qu’à un seul compte.']);
        $this->assertNull(Client::withoutGlobalScopes()->find($felix->client_id)->user_id);
        // Aucun compte créé pour rien (refusé avant la création)
        $this->assertSame($comptes, User::query()->count());

        // Le même compte que celui de la puce : permis
        $this->postJson("/api/v1/clients/{$felix->client_id}/compte", ['email' => $marc->email], $this->enTetes($jeton))->assertOk();
    }

    public function test_changer_la_fiche_d_un_animal_est_refuse_si_le_compte_de_la_nouvelle_fiche_n_est_pas_le_sien(): void
    {
        $clinique = Clinique::factory()->create();
        $felix = $this->felix($clinique);
        $marc = User::factory()->create();
        $felix->forceFill(['proprietaire_id' => $marc->id])->save();
        $autreFiche = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id, 'user_id' => User::factory()->create()->id]);
        $ficheSansCompte = Client::factory()->create(['organisation_id' => $clinique->societe->organisation_id]);
        ['jeton' => $jeton] = $this->employeAvecJeton($clinique);

        $this->putJson("/api/v1/patients/{$felix->id}", ['client_id' => $autreFiche->id], $this->enTetes($jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id' => 'Félix est déjà dans l’espace d’un autre compte que celui de cette fiche. Un animal n’appartient qu’à un seul compte.']);
        // Une fiche sans compte : permis (le compte de la puce reste le seul)
        $this->putJson("/api/v1/patients/{$felix->id}", ['client_id' => $ficheSansCompte->id], $this->enTetes($jeton))->assertOk();
    }

    public function test_aucun_chemin_ne_peut_donner_deux_comptes_a_un_animal(): void
    {
        $clinique = Clinique::factory()->create();
        $felix = $this->felix($clinique, User::factory()->create());

        $this->expectException(ValidationException::class);
        $felix->forceFill(['proprietaire_id' => User::factory()->create()->id])->save();
    }

    private function jetonSansClinique(User $user): string
    {
        app(ContexteClinique::class)->oublier();

        return app(EmetteurJetons::class)->emettreSansClinique($user)->plainTextToken;
    }

    public function test_une_commande_en_lecture_seule_liste_les_animaux_a_deux_comptes(): void
    {
        $clinique = Clinique::factory()->create();
        $this->artisan('pets:two-accounts')->expectsOutput('Aucun animal n’a deux comptes.')->assertExitCode(0);

        // Une donnée ancienne, d'avant la règle (posée sans passer par les modèles)
        $felix = $this->felix($clinique, User::factory()->create());
        DB::table('patients')->where('id', $felix->id)->update(['proprietaire_id' => User::factory()->create()->id]);
        $avant = DB::table('patients')->where('id', $felix->id)->value('proprietaire_id');

        $this->artisan('pets:two-accounts')->expectsOutputToContain('1 animal a deux comptes')->expectsOutputToContain('Félix')->assertExitCode(1);
        // Lecture seule : rien n'a changé
        $this->assertSame($avant, DB::table('patients')->where('id', $felix->id)->value('proprietaire_id'));
    }

    public function test_une_fiche_ne_prend_pas_un_compte_autre_que_celui_de_ses_animaux(): void
    {
        $clinique = Clinique::factory()->create();
        $felix = $this->felix($clinique);
        $felix->forceFill(['proprietaire_id' => User::factory()->create()->id])->save();
        $fiche = Client::withoutGlobalScopes()->find($felix->client_id);

        $this->expectException(ValidationException::class);
        $fiche->forceFill(['user_id' => User::factory()->create()->id])->save();
    }
}
