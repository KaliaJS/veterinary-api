<?php

namespace Tests\Feature\Plateforme;

use App\Mail\VerificationVeterinaireAFaire;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Vérification manuelle des vétérinaires par la plateforme : le
 * vétérinaire la demande depuis son profil, un modérateur (rôle donné
 * par commande) décide ; le badge vaut pour un GLN et l'historique garde
 * qui, quand et pourquoi.
 */
class VerificationVeterinaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const string GLN = '7601000123459';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['plateforme.verification_email' => 'verification@veterinary.test']);

        Http::fake(function (Request $requete) {
            if (str_ends_with($requete->url(), '/person/search')) {
                return Http::response(['entries' => [['id' => 71000, 'professions' => [['profession' => ['id' => 4]]]]]]);
            }

            return Http::response([
                'id' => 71000, 'gln' => self::GLN, 'name' => 'Fontaine', 'firstName' => 'Léa', 'hasPermission' => true,
                'professions' => [['profession' => ['id' => 4, 'textFr' => 'Vétérinaire'], 'diplomaType' => ['textFr' => 'Diplôme fédéral'], 'permissions' => []]],
            ]);
        });
    }

    /** @return array{0: User, 1: string} */
    private function moderateur(): array
    {
        ['user' => $moderateur, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Jim Modérateur']);
        $this->artisan('plateforme:moderateur', ['email' => $moderateur->email])->assertSuccessful();

        return [$moderateur->fresh(), $jeton];
    }

    public function test_le_veterinaire_demande_et_un_moderateur_le_verifie(): void
    {
        $clinique = Clinique::factory()->create();
        ['user' => $lea, 'jeton' => $jetonLea] = $this->employeAvecJeton($clinique);
        $lea->update(['name' => 'Léa Fontaine']);
        $entetesLea = $this->enTetes($jetonLea);

        // Sans GLN, pas de demande
        $this->postJson('/api/v1/auth/profil-veterinaire/verification', [], $entetesLea)->assertUnprocessable()->assertJsonValidationErrors('gln');

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => ['orthopedie']], $entetesLea)->assertOk();
        $this->postJson('/api/v1/auth/profil-veterinaire/verification', [], $entetesLea)
            ->assertOk()
            ->assertJsonPath('data.verification.statut', 'en_attente');
        Mail::assertSent(VerificationVeterinaireAFaire::class, fn ($mail) => $mail->hasTo('verification@veterinary.test'));

        // L'espace Plateforme est fermé aux autres comptes
        $this->getJson('/api/v1/plateforme/veterinaires', $entetesLea)->assertForbidden();

        [$jim, $jetonJim] = $this->moderateur();
        $entetesJim = $this->enTetes($jetonJim);

        $this->getJson('/api/v1/plateforme/veterinaires', $entetesJim)
            ->assertOk()
            ->assertJsonPath('data.0.nom', 'Léa Fontaine')
            ->assertJsonPath('data.0.gln_au_registre', true)
            ->assertJsonPath('data.0.equipes.0.cliniques', [$clinique->nom]);

        $this->getJson("/api/v1/plateforme/veterinaires/{$lea->id}", $entetesJim)
            ->assertJsonPath('data.registre.statut', 'meme_nom')
            ->assertJsonPath('data.registre.diplome', 'Diplôme fédéral');

        // Refuser demande un motif
        $this->postJson("/api/v1/plateforme/veterinaires/{$lea->id}/decision", ['decision' => 'refuse'], $entetesJim)
            ->assertUnprocessable()->assertJsonValidationErrors('note');

        $this->postJson("/api/v1/plateforme/veterinaires/{$lea->id}/decision", ['decision' => 'verifie', 'note' => 'Appel à la clinique'], $entetesJim)
            ->assertOk()
            ->assertJsonPath('data.statut', 'verifie')
            ->assertJsonPath('data.historique.0.decision', 'verifie')
            ->assertJsonPath('data.historique.0.par', 'Jim Modérateur')
            ->assertJsonPath('data.historique.1.decision', 'demandee');

        $fiche = Employe::withoutGlobalScopes()->where('user_id', $lea->id)->firstOrFail();
        $this->getJson("/api/v1/equipe/employes/{$fiche->id}", $entetesLea)->assertJsonPath('data.verifie', true);
        $this->getJson('/api/v1/auth/moi', $entetesJim)->assertJsonPath('data.user.est_moderateur', true);

        // Le badge vaut pour un GLN : en changer le retire
        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => '7601000999993', 'specialites' => ['orthopedie']], $entetesLea)
            ->assertOk()
            ->assertJsonPath('data.verification.statut', null);
    }

    public function test_un_moderateur_ne_verifie_pas_son_propre_compte(): void
    {
        [$jim, $jetonJim] = $this->moderateur();
        $jim->forceFill(['gln' => self::GLN])->save();

        $this->postJson("/api/v1/plateforme/veterinaires/{$jim->id}/decision", ['decision' => 'verifie'], $this->enTetes($jetonJim))
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertFalse($jim->fresh()->estVerifie());
    }

    public function test_un_badge_se_retire_avec_un_motif(): void
    {
        $lea = User::factory()->create(['name' => 'Léa Fontaine', 'gln' => self::GLN, 'verification_veterinaire' => 'verifie']);
        [, $jetonJim] = $this->moderateur();

        $this->postJson("/api/v1/plateforme/veterinaires/{$lea->id}/decision", ['decision' => 'retire', 'note' => 'Diplôme non reconnu'], $this->enTetes($jetonJim))
            ->assertOk()
            ->assertJsonPath('data.statut', null)
            ->assertJsonPath('data.historique.0.note', 'Diplôme non reconnu');
    }

    public function test_une_clinique_en_attente_se_valide_depuis_la_plateforme(): void
    {
        $fondateur = User::factory()->create(['name' => 'Léa Fontaine']);
        $organisation = Organisation::factory()->create(['verification' => 'en_attente', 'verification_raison' => 'nom_different', 'verification_gln' => self::GLN, 'inscrite_par' => $fondateur->id]);
        [, $jetonJim] = $this->moderateur();
        $entetes = $this->enTetes($jetonJim);

        $this->getJson('/api/v1/plateforme/organisations', $entetes)
            ->assertOk()
            ->assertJsonPath('data.0.id', $organisation->id)
            ->assertJsonPath('data.0.veterinaire.nom', 'Léa Fontaine')
            ->assertJsonPath('data.0.raison', 'nom_different');

        $this->postJson("/api/v1/plateforme/organisations/{$organisation->id}/valider", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.verification', 'verifiee');
        $this->getJson('/api/v1/plateforme/organisations', $entetes)->assertJsonCount(0, 'data');
    }
}
