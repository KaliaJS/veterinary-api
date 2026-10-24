<?php

namespace Tests\Feature\Auth;

use App\Mail\GlnRepris;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

    /** Un imposteur qui recopie le nom public et le GLN ne bloque pas le vrai titulaire (24.10.2026) ; le compte qui perd le GLN est prévenu. */
    public function test_un_gln_au_nom_concordant_sans_identite_verifiee_ne_bloque_pas(): void
    {
        Mail::fake();
        config(['plateforme.verification_email' => 'verification@plateforme.test']);
        $imposteur = User::factory()->create(['name' => 'Léa Fontaine', 'gln' => self::GLN, 'gln_verifie_le' => now()]);
        ['user' => $lea, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => []], $this->enTetes($jeton))->assertOk();
        $this->assertSame(self::GLN, $lea->refresh()->gln);
        $this->assertNull($imposteur->refresh()->gln);

        // Celui qui perd le GLN le sait (copie à la vérification de la plateforme), celui qui le prend ne reçoit rien
        Mail::assertQueued(GlnRepris::class, fn (GlnRepris $mail) => $mail->hasTo($imposteur->email) && $mail->hasBcc('verification@plateforme.test') && $mail->gln === self::GLN);
        Mail::assertNotQueued(GlnRepris::class, fn (GlnRepris $mail) => $mail->hasTo($lea->email));
        $this->assertStringNotContainsString($lea->email, (new GlnRepris(self::GLN))->render());
    }

    /** Ce que dit l'e-mail marche : le titulaire évincé ressaisit son GLN dans son profil et le récupère, l'autre compte est prévenu à son tour (24.10.2026). */
    public function test_le_titulaire_evince_recupere_son_gln_comme_le_dit_l_e_mail(): void
    {
        Mail::fake();
        ['user' => $titulaire, 'jeton' => $jetonTitulaire] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);
        $titulaire->forceFill(['gln' => self::GLN])->save();
        ['user' => $imposteur, 'jeton' => $jetonImposteur] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => []], $this->enTetes($jetonImposteur))->assertOk();
        $this->assertNull($titulaire->refresh()->gln);
        Mail::assertQueued(GlnRepris::class, fn (GlnRepris $mail) => $mail->hasTo($titulaire->email)
            && str_contains(str_replace("\u{a0}", ' ', $mail->render()), 'saisissez-le de nouveau dans Réglages, Profil'));

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => []], $this->enTetes($jetonTitulaire))->assertOk();
        $this->assertSame(self::GLN, $titulaire->refresh()->gln);
        $this->assertNull($imposteur->refresh()->gln);
        Mail::assertQueued(GlnRepris::class, fn (GlnRepris $mail) => $mail->hasTo($imposteur->email));
    }

    /** Le nouveau GLN ne s'enregistre pas : l'autre compte le garde et n'est pas prévenu (e-mail parti après la transaction seulement, 24.10.2026). */
    public function test_le_gln_repris_n_est_annonce_que_s_il_est_enregistre(): void
    {
        config(['mail.default' => 'array', 'queue.default' => 'sync']);
        $envoyes = fn () => Mail::mailer('array')->getSymfonyTransport()->messages()->count();
        $ancien = User::factory()->create(['gln' => self::GLN]);
        ['user' => $lea, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);
        $panne = true;
        User::saving(function (User $user) use ($lea, &$panne): void {
            if ($panne && $user->is($lea) && $user->gln === self::GLN) {
                throw new \RuntimeException('Enregistrement impossible');
            }
        });

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => []], $this->enTetes($jeton))->assertServerError();
        $this->assertSame(self::GLN, $ancien->refresh()->gln);
        $this->assertSame(0, $envoyes());

        $panne = false;
        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => []], $this->enTetes($jeton))->assertOk();
        $this->assertNull($ancien->refresh()->gln);
        $this->assertSame(1, $envoyes());
    }

    /** Une identité vérifiée pendant la recherche dans MedReg garde son GLN : revu sous verrou, refusé (24.10.2026). */
    public function test_une_identite_verifiee_pendant_la_recherche_garde_son_gln(): void
    {
        Mail::fake();
        $titulaire = User::factory()->create(['name' => 'Léa Fontaine', 'gln' => self::GLN]);
        ['user' => $autre, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);
        // Pendant l'appel à MedReg (après le premier contrôle), le titulaire fait vérifier son identité
        Http::fake(function (Request $requete) use ($titulaire) {
            $titulaire->forceFill(['identite_verifiee_le' => now()])->save();

            return str_ends_with($requete->url(), '/person/search')
                ? Http::response(['entries' => [['id' => 71000, 'professions' => [['profession' => ['id' => 4]]]]]])
                : Http::response(['id' => 71000, 'gln' => self::GLN, 'name' => 'Fontaine', 'firstName' => 'Léa', 'hasPermission' => true,
                    'professions' => [['profession' => ['id' => 4, 'textFr' => 'Vétérinaire'], 'diplomaType' => ['textFr' => 'Diplôme fédéral'], 'permissions' => []]]]);
        });

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => []], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('gln');
        $this->assertSame(self::GLN, $titulaire->refresh()->gln);
        $this->assertNull($autre->refresh()->gln);
        Mail::assertNothingQueued();
    }

    /** Deux comptes qui se reprennent le même GLN : cinq fois par jour au plus chacun (24.10.2026). */
    public function test_le_va_et_vient_d_un_gln_est_borne(): void
    {
        Mail::fake();
        ['jeton' => $jetonA] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);
        ['jeton' => $jetonB] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);
        $saisir = fn (string $jeton) => $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => []], $this->enTetes($jeton));

        foreach (range(1, 5) as $tour) {
            $saisir($jetonA)->assertOk();
            $saisir($jetonB)->assertOk();
        }
        $saisir($jetonA)->assertUnprocessable()->assertJsonValidationErrors(['gln' => 'Trop de changements de GLN aujourd’hui']);
        Mail::assertQueued(GlnRepris::class, 9);
    }

    /** Un changement de GLN refusé ne retire pas le badge de vétérinaire vérifié (24.10.2026). */
    public function test_un_changement_de_gln_refuse_garde_le_badge(): void
    {
        Mail::fake();
        ['user' => $lea, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);
        $lea->forceFill(['gln' => self::GLN, 'verification_veterinaire' => 'verifie', 'verification_veterinaire_le' => now()])->save();
        // Un autre compte garde ce GLN par son identité vérifiée
        User::factory()->create(['gln' => '7601000000002'])->forceFill(['identite_verifiee_le' => now()])->save();

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => '7601000000002', 'specialites' => []], $this->enTetes($jeton))->assertUnprocessable();

        $lea->refresh();
        $this->assertSame(self::GLN, $lea->gln);
        $this->assertSame('verifie', $lea->verification_veterinaire->value);
    }

    /** Un GLN inconnu de MedReg, refusé, ne retire pas non plus le badge (24.10.2026). */
    public function test_un_gln_inconnu_refuse_garde_le_badge(): void
    {
        ['user' => $lea, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);
        $lea->forceFill(['gln' => self::GLN, 'verification_veterinaire' => 'verifie', 'verification_veterinaire_le' => now()])->save();

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => '7601000000019', 'specialites' => []], $this->enTetes($jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('gln');

        $this->assertSame('verifie', $lea->refresh()->verification_veterinaire->value);
    }

    /** Un GLN gardé par une identité établie : rien ne change, personne n'est prévenu. */
    public function test_rien_n_est_envoye_quand_le_gln_n_est_pas_repris(): void
    {
        Mail::fake();
        User::factory()->create(['gln' => self::GLN, 'gln_verifie_le' => now()])->forceFill(['identite_verifiee_le' => now()])->save();
        ['jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'Léa Fontaine']);

        $this->putJson('/api/v1/auth/profil-veterinaire', ['gln' => self::GLN, 'specialites' => []], $this->enTetes($jeton))->assertUnprocessable();
        Mail::assertNothingQueued();
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
