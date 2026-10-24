<?php

namespace Tests\Feature\Equipe;

use App\Auth\EmetteurJetons;
use App\Auth\RolesParDefaut;
use App\Enums\FormuleSaas;
use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Mail\InvitationEquipe;
use App\Models\Absence;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Fiches RH : embauche par le code employé du compte (rôle dérivé du
 * poste, quota d'employés actifs de la formule, une fiche quelles que
 * soient les cliniques choisies), suspension d'accès à
 * effet immédiat, licenciement comme état (jamais de suppression),
 * solde de congés dérivé.
 */
class EmployeRhTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $employe;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->clinique = Clinique::factory()->create();

        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    public function test_l_embauche_cree_la_fiche_avec_le_role_derive_du_poste(): void
    {
        $compte = User::factory()->create(['name' => 'Nadia Rey', 'email' => 'nadia@exemple.ch']);

        $reponse = $this->postJson('/api/v1/equipe/employes', [
            'code' => User::formaterCodeEmploye($compte->codeEmploye()),
            'nom' => 'Dr Nadia Rey',
            'poste' => 'Vétérinaire',
            'taux' => 80,
            'salaire_mensuel' => 8500,
            'contrat_statut' => 'attente',
            'cliniques' => [$this->clinique->id],
        ], $this->enTetes($this->jeton));

        // Jamais sans son accord : une invitation, rien n'est créé avant qu'elle accepte
        $reponse->assertAccepted()->assertJsonPath('data.email', 'nadia@exemple.ch');
        $this->assertDatabaseMissing('employes', ['user_id' => $compte->id]);

        $fiche = $this->accepter($compte);
        $this->assertSame('Dr Nadia Rey', $fiche->nom);
        $this->assertSame('NR', $fiche->initiales);
        $this->assertSame('attente', $fiche->contrat_statut->value ?? $fiche->contrat_statut);
        $this->assertSame([$this->clinique->id], $fiche->cliniques()->pluck('cliniques.id')->all());

        $this->assertSame(RolesParDefaut::VETERINAIRE, $fiche->role->nom);
        $this->assertSame('CDI', $fiche->contrat);
        // Le compte de la personne est lié : elle pourra se connecter
        $this->assertSame($compte->id, $fiche->user_id);
    }

    public function test_l_embauche_d_une_asv_prend_le_role_asv_et_le_nom_du_compte(): void
    {
        $compte = User::factory()->create(['name' => 'Léa Perrin']);

        $this->postJson('/api/v1/equipe/employes', [
            'code' => strtolower($compte->codeEmploye()),
            'poste' => 'ASV · Accueil',
            'contrat_statut' => 'pdf',
            'cliniques' => [$this->clinique->id],
        ], $this->enTetes($this->jeton))->assertAccepted();

        $fiche = $this->accepter($compte);

        $this->assertSame(RolesParDefaut::ASV, $fiche->role->nom);
        $this->assertSame('Léa Perrin', $fiche->nom);
    }

    public function test_l_embauche_refuse_un_code_inconnu_et_une_personne_deja_dans_l_equipe(): void
    {
        $this->postJson('/api/v1/equipe/employes', [
            'code' => 'ZZZ-ZZZ', 'poste' => 'ASV', 'contrat_statut' => 'signe', 'cliniques' => [$this->clinique->id],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/equipe/employes', [
            'code' => $this->employe->user->codeEmploye(), 'poste' => 'ASV', 'contrat_statut' => 'signe', 'cliniques' => [$this->clinique->id],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_le_code_employe_montre_a_qui_il_appartient(): void
    {
        $compte = User::factory()->create(['name' => 'Nadia Rey', 'email' => 'nadia@exemple.ch']);

        $this->getJson('/api/v1/equipe/comptes/'.User::formaterCodeEmploye($compte->codeEmploye()), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertExactJson(['data' => ['nom' => 'Nadia Rey', 'email' => 'nadia@exemple.ch', 'employe_id' => null]]);

        // Déjà dans l'organisation : sa fiche, pour lui ajouter des cliniques
        $this->getJson('/api/v1/equipe/comptes/'.$this->employe->user->codeEmploye(), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.employe_id', $this->employe->id);

        $this->getJson('/api/v1/equipe/comptes/AAAAAA', $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_un_nouveau_code_employe_remplace_l_ancien(): void
    {
        $ancien = $this->getJson('/api/v1/auth/moi', $this->enTetes($this->jeton))->json('data.user.code_employe');

        $nouveau = $this->postJson('/api/v1/auth/code-employe', [], $this->enTetes($this->jeton))->assertOk()->json('data.code_employe');

        $this->assertNotSame($ancien, $nouveau);
        $this->getJson('/api/v1/equipe/comptes/'.$ancien, $this->enTetes($this->jeton))->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_chaque_compte_a_un_code_employe_stable_dans_sa_session(): void
    {
        $premier = $this->getJson('/api/v1/auth/moi', $this->enTetes($this->jeton))->json('data.user.code_employe');

        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}$/', $premier);
        $this->assertSame($premier, $this->getJson('/api/v1/auth/moi', $this->enTetes($this->jeton))->json('data.user.code_employe'));
    }

    public function test_l_embauche_respecte_la_limite_d_employes_actifs_de_la_formule(): void
    {
        // Formule Individuel : 2 employés actifs. Le porteur du jeton
        // en est un — la première embauche passe, la seconde non.
        $this->clinique->societe->organisation->update(['plan' => FormuleSaas::Individuel]);
        $entetes = $this->enTetes($this->jeton);

        $premier = User::factory()->create();
        $this->postJson('/api/v1/equipe/employes', [
            'code' => $premier->codeEmploye(), 'poste' => 'Vétérinaire', 'contrat_statut' => 'signe', 'toutes_les_cliniques' => true,
        ], $entetes)->assertAccepted();
        $this->accepter($premier);

        $this->postJson('/api/v1/equipe/employes', [
            'code' => User::factory()->create()->codeEmploye(), 'poste' => 'ASV', 'contrat_statut' => 'signe', 'cliniques' => [$this->clinique->id],
        ], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employes');
    }

    public function test_l_embauche_dans_toutes_les_cliniques_cree_une_seule_fiche(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        $marc = User::factory()->create();
        $this->postJson('/api/v1/equipe/employes', [
            'code' => $marc->codeEmploye(),
            'nom' => 'Dr Marc Weber',
            'poste' => 'Vétérinaire',
            'contrat_statut' => 'signe',
            'toutes_les_cliniques' => true,
        ], $this->enTetes($this->jeton))->assertAccepted();

        $this->assertSame([$this->clinique->id, $soeur->id], $this->accepter($marc)->cliniques()->orderBy('cliniques.id')->pluck('cliniques.id')->all());

        $this->assertSame(1, Employe::withoutGlobalScopes()->where('nom', 'Dr Marc Weber')->count());
        // Une personne compte une fois dans la limite de la formule
        $this->assertSame(2, $this->clinique->societe->organisation->employesActifs());
    }

    public function test_l_embauche_dans_quelques_cliniques_seulement(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        $troisieme = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);

        $asv = User::factory()->create();
        $this->postJson('/api/v1/equipe/employes', [
            'code' => $asv->codeEmploye(),
            'poste' => 'ASV',
            'contrat_statut' => 'signe',
            'cliniques' => [$soeur->id, $troisieme->id],
        ], $this->enTetes($this->jeton))->assertAccepted();
        $this->assertSame([$soeur->id, $troisieme->id], $this->accepter($asv)->cliniques()->orderBy('cliniques.id')->pluck('cliniques.id')->all());

        // Aucune clinique cochée : refusé
        $this->postJson('/api/v1/equipe/employes', [
            'code' => User::factory()->create()->codeEmploye(), 'poste' => 'ASV', 'contrat_statut' => 'signe', 'cliniques' => [],
        ], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliniques');
    }

    public function test_la_suspension_coupe_l_acces_immediatement_et_le_retablissement_le_rouvre(): void
    {
        ['employe' => $collegue, 'jeton' => $jetonCollegue] = $this->employeAvecJeton($this->clinique);

        $this->getJson('/api/v1/rendez-vous', $this->enTetes($jetonCollegue))->assertOk();

        $this->postJson("/api/v1/equipe/employes/{$collegue->id}/suspendre", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.acces_suspendu', true);

        // Le jeton lui-même est révoqué (23.10.2026)
        $this->getJson('/api/v1/rendez-vous', $this->enTetes($jetonCollegue))->assertUnauthorized();

        $this->postJson("/api/v1/equipe/employes/{$collegue->id}/retablir", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.acces_suspendu', false);

        // Rétablie, la personne se reconnecte et retrouve la clinique
        $nouveau = app(EmetteurJetons::class)->emettre($collegue->user)['jeton']->plainTextToken;
        $this->getJson('/api/v1/rendez-vous', $this->enTetes($nouveau))->assertOk();
    }

    public function test_le_retablissement_reverifie_le_quota_de_la_formule(): void
    {
        ['employe' => $collegue] = $this->employeAvecJeton($this->clinique);
        $collegue->update(['acces_suspendu' => true]);

        // Individuel : 2 actifs max — déjà 2 en poste, le retour du
        // suspendu dépasserait.
        $this->employeAvecJeton($this->clinique);
        $this->clinique->societe->organisation->update(['plan' => FormuleSaas::Individuel]);

        $this->postJson("/api/v1/equipe/employes/{$collegue->id}/retablir", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('acces');
    }

    public function test_le_licenciement_est_un_etat_notifie_revocable(): void
    {
        ['employe' => $collegue] = $this->employeAvecJeton($this->clinique);
        $fin = aujourdhui()->addMonths(2)->endOfMonth()->toDateString();
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/equipe/employes/{$collegue->id}/licencier", [
            'fin_contrat_le' => $fin,
            'motif' => 'Restructuration du service',
            'revoquer_acces' => true,
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('data.licenciement.fin_contrat_le', $fin)
            ->assertJsonPath('data.licenciement.notifie_le', aujourdhui()->toDateString())
            ->assertJsonPath('data.acces_suspendu', true);

        // Jamais deux notifications empilées.
        $this->postJson("/api/v1/equipe/employes/{$collegue->id}/licencier", [
            'fin_contrat_le' => $fin,
        ], $entetes)->assertUnprocessable()->assertJsonValidationErrors(['licenciement' => 'Un licenciement est déjà notifié, annulez-le d’abord.']);

        // L'annulation efface l'état — l'accès, lui, reste un geste à part.
        $this->postJson("/api/v1/equipe/employes/{$collegue->id}/annuler-licenciement", [], $entetes)
            ->assertOk()
            ->assertJsonMissingPath('data.licenciement');

        $this->assertTrue($collegue->fresh()->acces_suspendu);
    }

    public function test_le_solde_de_conges_est_derive_du_droit_du_pris_et_du_planifie(): void
    {
        $this->employe->update(['conges_droit' => 25, 'conges_pris' => 10]);

        // 3 jours validés à venir + un congé passé (ignoré) + une
        // demande en attente (ignorée).
        Absence::factory()->create([
            'employe_id' => $this->employe->id,
            'type' => TypeAbsence::Conge,
            'statut' => StatutAbsence::Validee,
            'debut_le' => aujourdhui()->addDays(10),
            'fin_le' => aujourdhui()->addDays(12),
        ]);
        Absence::factory()->create([
            'employe_id' => $this->employe->id,
            'type' => TypeAbsence::Conge,
            'statut' => StatutAbsence::Validee,
            'debut_le' => aujourdhui()->subDays(20),
            'fin_le' => aujourdhui()->subDays(18),
        ]);
        Absence::factory()->create([
            'employe_id' => $this->employe->id,
            'type' => TypeAbsence::Conge,
            'statut' => StatutAbsence::EnAttente,
            'debut_le' => aujourdhui()->addDays(20),
            'fin_le' => aujourdhui()->addDays(24),
        ]);

        $this->getJson("/api/v1/equipe/employes/{$this->employe->id}", $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.conges.droit', 25)
            ->assertJsonPath('data.conges.pris', 10)
            ->assertJsonPath('data.conges.planifies', 3)
            ->assertJsonPath('data.conges.solde', 12);
    }

    public function test_la_modification_de_la_fiche(): void
    {
        $this->patchJson("/api/v1/equipe/employes/{$this->employe->id}", [
            'taux' => 60,
            'contrat' => 'CDD → 12.2026',
            'conges_droit' => 20,
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.taux', 60)
            ->assertJsonPath('data.contrat', 'CDD → 12.2026');
    }

    /** La personne accepte l'invitation reçue par e-mail : sa fiche. */
    private function accepter(User $compte): Employe
    {
        $lien = null;
        Mail::assertSent(InvitationEquipe::class, function (InvitationEquipe $mail) use (&$lien, $compte): bool {
            if ($mail->hasTo($compte->email)) {
                $lien = $mail->jeton;
            }

            return true;
        });
        $jeton = app(EmetteurJetons::class)->emettreSansClinique($compte)->plainTextToken;
        $this->postJson("/api/v1/invitations/{$lien}/accepter", [], $this->enTetes($jeton))->assertCreated();

        return Employe::withoutGlobalScopes()->where('user_id', $compte->id)->firstOrFail();
    }
}
