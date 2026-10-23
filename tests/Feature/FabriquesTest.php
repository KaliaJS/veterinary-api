<?php

namespace Tests\Feature;

use App\Models\Facture;
use App\Models\Passkey;
use App\Models\User;
use Database\Factories\PaiementFactory;
use Database\Factories\PasskeyFactory;
use Database\Factories\SiteWebFactory;
use Database\Factories\TerminalPaiementFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;
use Webauthn\CredentialRecord;

/**
 * Fabriques des modèles sans HasFactory (paiement, terminal, site,
 * passkey) : des fiches valides, cohérentes avec ce que l'application
 * enregistre, et relues telles quelles.
 */
class FabriquesTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    public function test_un_paiement_suit_la_clinique_de_sa_facture_et_son_terminal(): void
    {
        $facture = Facture::factory()->create();
        $terminal = TerminalPaiementFactory::new()->create(['clinique_id' => $facture->clinique_id]);

        $paiement = PaiementFactory::new()->surTerminal($terminal)->reussi()->create(['facture_id' => $facture->id]);

        $this->assertSame($facture->clinique_id, $paiement->clinique_id);
        $this->assertSame('tpe', $paiement->moyen->value);
        $this->assertSame('reussi', $paiement->statut->value);
        $this->assertSame('85.50', $paiement->montant);
        $this->assertSame($facture->clinique_id, PaiementFactory::new()->create(['facture_id' => $facture->id])->clinique_id);
    }

    public function test_une_passkey_se_relit_et_parait_dans_la_liste_de_son_compte(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $passkey = PasskeyFactory::new()->create(['user_id' => $user->id]);
        PasskeyFactory::new()->for(User::factory())->create();

        $relue = Passkey::query()->findOrFail($passkey->id)->donnees;
        $this->assertInstanceOf(CredentialRecord::class, $relue);
        $this->assertSame($passkey->credential_id, Base64UrlSafe::encodeUnpadded($relue->publicKeyCredentialId));

        $this->getJson('/api/v1/auth/passkeys', $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $passkey->id);
    }

    public function test_un_site_publie_se_lit_par_son_slug(): void
    {
        $site = SiteWebFactory::new()->publie()->create();

        $this->getJson("/api/v1/public/sites/{$site->slug}")->assertOk();
    }
}
