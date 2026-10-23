<?php

namespace Tests\Feature\Plateforme;

use App\Mail\CliniqueRefusee;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Refus d'une clinique inscrite depuis l'espace Plateforme : un modérateur
 * dit pourquoi, la clinique passe refusée et le vétérinaire qui l'a
 * inscrite reçoit le motif. Une décision prise ne se refait pas, et
 * personne d'autre qu'un modérateur ne décide.
 */
class RefusOrganisationPlateformeTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function moderateur(): string
    {
        ['user' => $moderateur, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Jim Modérateur']);
        $this->artisan('plateforme:moderateur', ['email' => $moderateur->email])->assertSuccessful();

        return $jeton;
    }

    private function enAttente(): Organisation
    {
        $fondateur = User::factory()->create(['name' => 'Paul Martin']);

        return Organisation::factory()->create([
            'verification' => 'en_attente',
            'verification_raison' => 'introuvable',
            'verification_gln' => '7601000999993',
            'inscrite_par' => $fondateur->id,
        ]);
    }

    public function test_un_moderateur_refuse_une_clinique_en_attente_avec_un_motif(): void
    {
        $organisation = $this->enAttente();
        $entetes = $this->enTetes($this->moderateur());

        $this->postJson("/api/v1/plateforme/organisations/{$organisation->id}/refuser", ['note' => 'Aucune autorisation de pratiquer à ce nom.'], $entetes)
            ->assertOk()
            ->assertJsonPath('data.id', $organisation->id)
            ->assertJsonPath('data.verification', 'refusee')
            ->assertJsonPath('data.note', 'Aucune autorisation de pratiquer à ce nom.')
            ->assertJsonPath('data.veterinaire.nom', 'Paul Martin');

        $this->assertSame('refusee', $organisation->refresh()->verification->value);
        Mail::assertSent(CliniqueRefusee::class, fn (CliniqueRefusee $mail) => $mail->hasTo($organisation->inscritePar->email));

        // Elle quitte la liste d'attente pour celle des refusées
        $this->getJson('/api/v1/plateforme/organisations', $entetes)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/plateforme/organisations?verification=refusee', $entetes)
            ->assertOk()->assertJsonPath('data.0.id', $organisation->id);
    }

    public function test_un_moderateur_ne_refuse_pas_sa_propre_clinique(): void
    {
        ['user' => $moderateur, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Jim Modérateur']);
        $this->artisan('plateforme:moderateur', ['email' => $moderateur->email])->assertSuccessful();
        $organisation = $this->enAttente();
        $organisation->forceFill(['inscrite_par' => $moderateur->id])->save();

        $this->postJson("/api/v1/plateforme/organisations/{$organisation->id}/refuser", ['note' => 'Essai.'], $this->enTetes($jeton))
            ->assertUnprocessable();

        $this->assertSame('en_attente', $organisation->refresh()->verification->value);
        Mail::assertNothingSent();
    }

    public function test_un_refus_sans_motif_est_refuse(): void
    {
        $organisation = $this->enAttente();

        $this->postJson("/api/v1/plateforme/organisations/{$organisation->id}/refuser", [], $this->enTetes($this->moderateur()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->assertSame('en_attente', $organisation->refresh()->verification->value);
        Mail::assertNotSent(CliniqueRefusee::class);
    }

    public function test_une_clinique_deja_decidee_ne_se_refuse_plus(): void
    {
        $organisation = $this->enAttente();
        $organisation->forceFill(['verification' => 'verifiee'])->save();

        $this->postJson("/api/v1/plateforme/organisations/{$organisation->id}/refuser", ['note' => 'Trop tard.'], $this->enTetes($this->moderateur()))
            ->assertUnprocessable();

        $this->assertSame('verifiee', $organisation->refresh()->verification->value);
        Mail::assertNotSent(CliniqueRefusee::class);
    }

    public function test_seul_un_moderateur_refuse_une_clinique(): void
    {
        $organisation = $this->enAttente();
        ['jeton' => $proprietaire] = $this->proprietaireAvecJeton();
        ['jeton' => $employe] = $this->administrateurAvecJeton();

        $this->postJson("/api/v1/plateforme/organisations/{$organisation->id}/refuser", ['note' => 'Non.'])->assertUnauthorized();
        $this->postJson("/api/v1/plateforme/organisations/{$organisation->id}/refuser", ['note' => 'Non.'], $this->enTetes($proprietaire))->assertForbidden();
        $this->postJson("/api/v1/plateforme/organisations/{$organisation->id}/refuser", ['note' => 'Non.'], $this->enTetes($employe))->assertForbidden();

        $this->assertSame('en_attente', $organisation->refresh()->verification->value);
    }

    public function test_une_organisation_inconnue_est_introuvable(): void
    {
        $entetes = $this->enTetes($this->moderateur());

        $this->postJson('/api/v1/plateforme/organisations/999999/refuser', ['note' => 'Inconnue.'], $entetes)->assertNotFound();
        $this->postJson('/api/v1/plateforme/organisations/abc/refuser', ['note' => 'Inconnue.'], $entetes)->assertNotFound();
    }
}
