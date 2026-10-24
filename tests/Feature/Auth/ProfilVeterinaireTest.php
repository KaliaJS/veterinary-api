<?php

namespace Tests\Feature\Auth;

use App\Models\Clinique;
use App\Models\Employe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Profil vétérinaire de son propre compte : son GLN, cherché dans MedReg
 * au même nom (« trouvé au registre » seulement alors), et ses
 * spécialités, qu'il gère lui-même et qui suivent sa fiche dans toutes
 * ses organisations.
 */
class ProfilVeterinaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const string GLN = '7601000123459';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $requete) {
            if (str_ends_with($requete->url(), '/person/search')) {
                return Http::response([
                    'entries' => $requete['gln'] === self::GLN ? [['id' => 71000, 'professions' => [['profession' => ['id' => 4]]]]] : [],
                ]);
            }

            return Http::response([
                'id' => 71000,
                'gln' => self::GLN,
                'name' => 'Fontaine',
                'firstName' => 'Léa',
                'hasPermission' => true,
                'professions' => [['profession' => ['id' => 4, 'textFr' => 'Vétérinaire'], 'diplomaType' => ['textFr' => 'Diplôme fédéral'], 'permissions' => []]],
            ]);
        });
    }

    public function test_le_veterinaire_declare_son_gln_et_ses_specialites(): void
    {
        $clinique = Clinique::factory()->create();
        ['user' => $lea, 'jeton' => $jeton] = $this->employeAvecJeton($clinique);
        $lea->update(['name' => 'Léa Fontaine']);
        $entetes = $this->enTetes($jeton);

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => '7601 0001 2345 9', 'specialites' => ['ophtalmologie', 'orthopedie']], $entetes)
            ->assertOk()
            ->assertJsonPath('data.gln', self::GLN)
            ->assertJsonPath('data.gln_au_registre', true)
            ->assertJsonPath('data.registre_resultat', 'trouve')
            ->assertJsonPath('data.specialites', ['orthopedie', 'ophtalmologie']);

        // Ses spécialités sont celles de sa fiche, partout
        $fiche = Employe::withoutGlobalScopes()->where('user_id', $lea->id)->firstOrFail();
        $this->getJson("/api/v1/equipe/employes/{$fiche->id}", $entetes)->assertJsonPath('data.specialites', ['orthopedie', 'ophtalmologie']);

        $this->getJson('/api/v1/auth/profil-veterinaire', $entetes)->assertJsonPath('data.specialites', ['orthopedie', 'ophtalmologie']);
    }

    public function test_un_autre_nom_ou_un_gln_inconnu_ne_passe_pas_au_registre(): void
    {
        ['user' => $compte, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Marc Dupont']);

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => []], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.gln_au_registre', false)
            ->assertJsonPath('data.registre_resultat', 'nom_different');

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => '7601000123450', 'specialites' => []], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('gln');
    }

    public function test_un_gln_deja_verifie_chez_un_autre_compte_est_refuse(): void
    {
        // Identité établie (Stripe Identity ou modérateur) : le GLN est réservé
        User::factory()->create(['gln' => self::GLN, 'gln_verifie_le' => now()])->forceFill(['identite_verifiee_le' => now()])->save();
        ['jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => []], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('gln');
    }

    /** Un imposteur qui recopie le nom public et le GLN ne bloque pas le vrai titulaire (24.10.2026). */
    public function test_un_gln_au_nom_concordant_sans_identite_verifiee_ne_bloque_pas(): void
    {
        $imposteur = User::factory()->create(['name' => 'Léa Fontaine', 'gln' => self::GLN, 'gln_verifie_le' => now()]);
        ['user' => $lea, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => []], $this->enTetes($jeton))->assertOk();
        $this->assertSame(self::GLN, $lea->refresh()->gln);
        $this->assertNull($imposteur->refresh()->gln);
    }

    public function test_un_gln_inconnu_de_medreg_n_est_pas_enregistre(): void
    {
        ['user' => $lea, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => '7601000999993', 'specialites' => []], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('gln');
        $this->assertNull($lea->refresh()->gln);
    }

    public function test_un_gln_seulement_saisi_par_un_autre_compte_ne_bloque_pas_son_titulaire(): void
    {
        $squatteur = User::factory()->create(['gln' => self::GLN, 'gln_verifie_le' => null]);
        ['user' => $lea, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => []], $this->enTetes($jeton))->assertOk();

        $this->assertSame(self::GLN, $lea->fresh()->gln);
        $this->assertNull($squatteur->fresh()->gln);
    }
}
