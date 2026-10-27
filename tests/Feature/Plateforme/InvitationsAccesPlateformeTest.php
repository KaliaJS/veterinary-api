<?php

namespace Tests\Feature\Plateforme;

use App\Mail\InvitationAccesEnvoyee;
use App\Models\InvitationAcces;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Liste des invitations, gérée par les modérateurs de la plateforme
 * (27.10.2026) : ajouter une adresse (elle reçoit un e-mail), voir qui est
 * invité et qui a créé son compte, retirer une invitation pas encore
 * utilisée. Et la commande `plateforme:inviter` pour le premier compte.
 */
class InvitationsAccesPlateformeTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    /** @return array{0: User, 1: array<string, string>} */
    private function moderateur(): array
    {
        ['user' => $moderateur, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Jim Modérateur']);
        $this->artisan('plateforme:moderateur', ['email' => $moderateur->email])->assertSuccessful();

        return [$moderateur->fresh(), $this->enTetes($jeton)];
    }

    public function test_un_moderateur_ajoute_une_adresse_qui_recoit_un_email(): void
    {
        Mail::fake();
        [$jim, $entetes] = $this->moderateur();

        $this->postJson('/api/v1/plateforme/invitations', ['email' => ' Marie@Lac.ch ', 'note' => 'Clinique du Lac'], $entetes)
            ->assertCreated()
            ->assertJsonPath('data.email', 'marie@lac.ch')
            ->assertJsonPath('data.note', 'Clinique du Lac')
            ->assertJsonPath('data.invitee_par', 'Jim Modérateur')
            ->assertJsonPath('data.compte_cree', false);

        $this->assertTrue(InvitationAcces::query()->where('email', 'marie@lac.ch')->where('invitee_par', $jim->id)->exists());
        Mail::assertSent(InvitationAccesEnvoyee::class, fn (InvitationAccesEnvoyee $mail): bool => $mail->hasTo('marie@lac.ch'));
        config(['app.frontend_url' => 'https://veterinary.app']);
        $this->assertStringContainsString('https://veterinary.app/?connexion=1', (new InvitationAccesEnvoyee)->render());
    }

    /** L'e-mail ne part pas (Resend en panne) : rien n'est gardé, on peut réessayer. */
    public function test_un_email_qui_ne_part_pas_ne_garde_pas_l_invitation(): void
    {
        [, $entetes] = $this->moderateur();
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new TransportException('Resend injoignable'));

        $this->postJson('/api/v1/plateforme/invitations', ['email' => 'marie@lac.ch'], $entetes)
            ->assertStatus(503)
            ->assertJsonPath('message', 'L’e-mail d’invitation n’a pas pu partir : réessayez dans un moment.');

        $this->assertSame(0, InvitationAcces::query()->count());
    }

    public function test_une_adresse_deja_invitee_ou_qui_a_un_compte_est_refusee(): void
    {
        Mail::fake();
        [, $entetes] = $this->moderateur();
        InvitationAcces::query()->create(['email' => 'marie@lac.ch']);
        User::factory()->create(['email' => 'paul@exemple.ch']);

        $this->postJson('/api/v1/plateforme/invitations', ['email' => 'MARIE@lac.ch'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Cette adresse est déjà invitée.']);
        $this->postJson('/api/v1/plateforme/invitations', ['email' => 'paul@exemple.ch'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Cette adresse a déjà un compte.']);
        $this->postJson('/api/v1/plateforme/invitations', ['email' => 'pas-une-adresse'], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        Mail::assertNothingSent();
    }

    public function test_un_moderateur_voit_la_liste_avec_qui_a_invite_et_les_comptes_crees(): void
    {
        [$jim, $entetes] = $this->moderateur();
        InvitationAcces::query()->create(['email' => 'marie@lac.ch', 'note' => 'Clinique du Lac', 'invitee_par' => $jim->id]);
        $utilisee = InvitationAcces::query()->create(['email' => 'lea@lac.ch']);
        $utilisee->forceFill(['utilisee_le' => now()])->save();

        $liste = $this->getJson('/api/v1/plateforme/invitations', $entetes)->assertOk()->json('data');

        $this->assertSame(['lea@lac.ch', 'marie@lac.ch'], array_column($liste, 'email'));
        $parAdresse = array_column($liste, null, 'email');
        $this->assertSame('Clinique du Lac', $parAdresse['marie@lac.ch']['note']);
        $this->assertSame('Jim Modérateur', $parAdresse['marie@lac.ch']['invitee_par']);
        $this->assertFalse($parAdresse['marie@lac.ch']['compte_cree']);
        $this->assertTrue($parAdresse['lea@lac.ch']['compte_cree']);
    }

    public function test_un_moderateur_retire_une_invitation_pas_encore_utilisee(): void
    {
        [, $entetes] = $this->moderateur();
        $libre = InvitationAcces::query()->create(['email' => 'marie@lac.ch']);
        $utilisee = InvitationAcces::query()->create(['email' => 'lea@lac.ch']);
        $utilisee->forceFill(['utilisee_le' => now()])->save();

        $this->deleteJson("/api/v1/plateforme/invitations/{$libre->id}", [], $entetes)->assertNoContent();
        $this->deleteJson("/api/v1/plateforme/invitations/{$utilisee->id}", [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['invitation' => 'Le compte est déjà créé : cette invitation ne se retire plus.']);

        $this->assertNull($libre->fresh());
        $this->assertNotNull($utilisee->fresh());
    }

    public function test_seul_un_moderateur_gere_les_invitations(): void
    {
        ['jeton' => $jeton] = $this->employeAvecJeton();
        $invitation = InvitationAcces::query()->create(['email' => 'marie@lac.ch']);
        $entetes = $this->enTetes($jeton);

        $this->getJson('/api/v1/plateforme/invitations', $entetes)->assertForbidden();
        $this->postJson('/api/v1/plateforme/invitations', ['email' => 'paul@exemple.ch'], $entetes)->assertForbidden();
        $this->deleteJson("/api/v1/plateforme/invitations/{$invitation->id}", [], $entetes)->assertForbidden();
        $this->getJson('/api/v1/plateforme/invitations')->assertUnauthorized();
        $this->assertSame(1, InvitationAcces::query()->count());
    }

    public function test_la_commande_ajoute_une_adresse_sans_ecran(): void
    {
        Mail::fake();

        $this->artisan('plateforme:inviter', ['email' => 'Jim@Meteora.systems', '--note' => 'Premier compte'])->assertSuccessful();
        $this->artisan('plateforme:inviter', ['email' => 'jim@meteora.systems'])->assertSuccessful()->expectsOutputToContain('déjà invitée');

        $this->assertSame(1, InvitationAcces::query()->where('email', 'jim@meteora.systems')->where('note', 'Premier compte')->count());
        Mail::assertNothingSent();
    }

    public function test_la_commande_previent_par_email_sur_demande(): void
    {
        Mail::fake();

        $this->artisan('plateforme:inviter', ['email' => 'marie@lac.ch', '--prevenir' => true])->assertSuccessful();

        Mail::assertSent(InvitationAccesEnvoyee::class, fn (InvitationAccesEnvoyee $mail): bool => $mail->hasTo('marie@lac.ch'));
    }
}
