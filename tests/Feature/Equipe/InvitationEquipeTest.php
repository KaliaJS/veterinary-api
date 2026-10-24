<?php

namespace Tests\Feature\Equipe;

use App\Mail\InvitationEquipe;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\InvitationEmploye;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Invitation par e-mail, l'autre chemin que le code employé : rien n'est
 * créé avant que la personne accepte, connectée avec l'adresse invitée
 * (son compte naît à la connexion s'il n'existe pas). Chaque personne a
 * son compte.
 */
class InvitationEquipeTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Clinique $soeur;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->clinique = Clinique::factory()->create();
        $this->soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique, ['nom' => 'Dr Anna Meyer']);
    }

    /** @return array<string, mixed> */
    private function invitation(string $email = 'Nina.Rossi@example.ch'): array
    {
        return [
            'email' => $email,
            'nom' => 'Dr Nina Rossi',
            'poste' => 'Vétérinaire',
            'contrat_statut' => 'signe',
            'cliniques' => [$this->soeur->id],
        ];
    }

    private function lienEnvoye(): string
    {
        $lien = null;
        Mail::assertSent(InvitationEquipe::class, function (InvitationEquipe $mail) use (&$lien): bool {
            $lien = $mail->jeton;

            return true;
        });

        return $lien;
    }

    public function test_une_personne_invitee_accepte_avec_son_adresse_et_rejoint_l_equipe(): void
    {
        $this->postJson('/api/v1/equipe/invitations', $this->invitation(), $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.email', 'nina.rossi@example.ch')
            ->assertJsonPath('data.invitee_par', 'Dr Anna Meyer');

        Mail::assertSent(InvitationEquipe::class, fn (InvitationEquipe $mail) => $mail->hasTo('nina.rossi@example.ch'));
        $lien = $this->lienEnvoye();
        $this->getJson('/api/v1/equipe/invitations', $this->enTetes($this->jeton))->assertJsonCount(1, 'data');

        // Rien n'est créé avant l'acceptation
        $this->assertDatabaseMissing('employes', ['nom' => 'Dr Nina Rossi']);

        // Son compte (né à la connexion), sans clinique
        ['user' => $nina, 'jeton' => $jetonNina] = $this->proprietaireAvecJeton(['email' => 'nina.rossi@example.ch', 'name' => 'Nina Rossi']);

        $this->getJson("/api/v1/invitations/{$lien}", $this->enTetes($jetonNina))
            ->assertOk()
            ->assertJsonPath('data.statut', 'en_attente')
            ->assertJsonPath('data.pour_ce_compte', true)
            ->assertJsonPath('data.cliniques', [$this->soeur->nom]);

        $this->postJson("/api/v1/invitations/{$lien}/accepter", [], $this->enTetes($jetonNina))
            ->assertCreated()
            ->assertJsonPath('data.clinique_id', $this->soeur->id);

        $fiche = Employe::withoutGlobalScopes()->where('user_id', $nina->id)->firstOrFail();
        $this->assertSame('Dr Nina Rossi', $fiche->nom);
        $this->assertSame([$this->soeur->id], $fiche->cliniques()->pluck('cliniques.id')->all());

        // La session s'ouvre sur sa clinique ; l'invitation n'est plus en attente
        $jetonClinique = $this->postJson('/api/v1/auth/clinique', ['clinique_id' => $this->soeur->id], $this->enTetes($jetonNina))
            ->assertOk()
            ->json('data.token');
        $this->getJson('/api/v1/equipe/invitations', $this->enTetes($this->jeton))->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/invitations/{$lien}/accepter", [], $this->enTetes($jetonClinique))->assertUnprocessable()->assertJsonValidationErrors(['invitation' => 'Cette invitation n’est plus valable. Demandez-en une nouvelle à la clinique.']);
    }

    public function test_seule_l_adresse_invitee_peut_accepter(): void
    {
        $this->postJson('/api/v1/equipe/invitations', $this->invitation(), $this->enTetes($this->jeton))->assertCreated();
        $lien = $this->lienEnvoye();
        ['jeton' => $autre] = $this->proprietaireAvecJeton(['email' => 'quelqu.un@example.ch']);

        $this->getJson("/api/v1/invitations/{$lien}", $this->enTetes($autre))->assertJsonPath('data.pour_ce_compte', false);
        $this->postJson("/api/v1/invitations/{$lien}/accepter", [], $this->enTetes($autre))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->getJson('/api/v1/invitations/inconnu', $this->enTetes($autre))->assertNotFound();
    }

    public function test_renvoyer_change_le_lien_et_annuler_le_ferme(): void
    {
        $id = $this->postJson('/api/v1/equipe/invitations', $this->invitation(), $this->enTetes($this->jeton))->json('data.id');
        $premier = $this->lienEnvoye();
        ['jeton' => $jetonNina] = $this->proprietaireAvecJeton(['email' => 'nina.rossi@example.ch']);

        $this->postJson("/api/v1/equipe/invitations/{$id}/renvoyer", [], $this->enTetes($this->jeton))->assertOk();
        $this->getJson("/api/v1/invitations/{$premier}", $this->enTetes($jetonNina))->assertNotFound();

        $this->deleteJson("/api/v1/equipe/invitations/{$id}", [], $this->enTetes($this->jeton))->assertNoContent();
        $this->assertNotNull(InvitationEmploye::find($id)->annulee_le);
    }

    /** Par adresse : trois envois en quinze minutes, puis refus ; l'invitation en cours reste valable. */
    public function test_une_adresse_ne_recoit_pas_d_invitations_en_boucle(): void
    {
        $inviter = fn () => $this->postJson('/api/v1/equipe/invitations', $this->invitation(), $this->enTetes($this->jeton));
        $id = $inviter()->assertCreated()->json('data.id');
        $this->postJson("/api/v1/equipe/invitations/{$id}/renvoyer", [], $this->enTetes($this->jeton))->assertOk();
        $this->postJson("/api/v1/equipe/invitations/{$id}/renvoyer", [], $this->enTetes($this->jeton))->assertOk();
        $this->postJson("/api/v1/equipe/invitations/{$id}/renvoyer", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $inviter()->assertUnprocessable()->assertJsonValidationErrors('email');
        Mail::assertSentCount(3);
        $this->assertNull(InvitationEmploye::query()->findOrFail($id)->annulee_le);

        $this->travel(16)->minutes();
        $this->postJson("/api/v1/equipe/invitations/{$id}/renvoyer", [], $this->enTetes($this->jeton))->assertOk();
    }

    public function test_une_personne_deja_dans_l_equipe_ne_s_invite_pas(): void
    {
        $compte = User::factory()->create(['email' => 'deja@example.ch']);
        Employe::factory()->dans($this->clinique)->create(['user_id' => $compte->id]);

        $this->postJson('/api/v1/equipe/invitations', $this->invitation('deja@example.ch'), $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        Mail::assertNothingSent();
    }
}
