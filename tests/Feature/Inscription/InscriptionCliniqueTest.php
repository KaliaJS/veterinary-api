<?php

namespace Tests\Feature\Inscription;

use App\Auth\ContexteClinique;
use App\Auth\RolesParDefaut;
use App\Mail\CliniqueRefusee;
use App\Mail\CliniqueVerifiee;
use App\Mail\VerificationCliniqueAFaire;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\Societe;
use App\Models\User;
use App\Paiements\PasserelleAbonnement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Doubles\PasserelleAbonnementFactice;
use Tests\TestCase;

/**
 * Inscription d'une clinique par un vétérinaire : GLN vérifié dans MedReg
 * (simulé), sinon vérification à la main par la plateforme (liens signés),
 * et une application fermée tant que l'organisation n'est ni vérifiée ni
 * payée.
 */
class InscriptionCliniqueTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private const string GLN = '7601000123459';

    private const string AUTRE_GLN = '7601000999993';

    private bool $medregEnPanne = false;

    /** @var array<string, array{prenom: string, nom: string}> Vétérinaires de MedReg, par GLN */
    private array $registre = [self::GLN => ['prenom' => 'Léa Marie', 'nom' => 'Fontaine']];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['plateforme.verification_email' => 'verification@plateforme.test']);

        Http::fake(function (Request $requete) {
            if ($this->medregEnPanne) {
                return Http::response(null, 503);
            }

            if (str_ends_with($requete->url(), '/person/search')) {
                $fiche = $this->registre[$requete['gln']] ?? null;

                return Http::response([
                    'entries' => $fiche ? [['id' => 71000, 'name' => $fiche['nom'], 'firstName' => $fiche['prenom'], 'professions' => [['profession' => ['id' => 4]]]]] : [],
                    'matchCount' => $fiche ? 1 : 0,
                ]);
            }

            $gln = (string) array_key_first($this->registre);
            $fiche = $this->registre[$gln];

            return Http::response([
                'id' => 71000,
                'gln' => $gln,
                'name' => $fiche['nom'],
                'firstName' => $fiche['prenom'],
                'hasPermission' => true,
                'professions' => [[
                    'profession' => ['id' => 4, 'textFr' => 'Vétérinaire'],
                    'diplomaType' => ['textFr' => 'Diplôme fédéral'],
                    'permissions' => [['canton' => ['textFr' => 'Vaud']]],
                ]],
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function inscription(array $vous = []): array
    {
        return [
            'prenom' => 'Léa',
            'nom' => 'Fontaine',
            'gln' => self::GLN,
            ...$vous,
            'societe' => ['nom' => 'Cabinet Fontaine Sàrl', 'ide' => 'che-123.456.789'],
            'clinique' => ['nom' => 'Cabinet du Lac', 'rue' => 'Rue du Lac 3', 'npa' => '1003', 'ville' => 'Lausanne', 'tel' => '021 555 00 00', 'email' => 'contact@cabinet-du-lac.test'],
        ];
    }

    public function test_le_gln_est_controle_avant_toute_recherche(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->postJson('/api/v1/inscription-clinique/gln', ['prenom' => 'Léa', 'nom' => 'Fontaine', 'gln' => '7601000123458'], $this->enTetes($jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gln');

        Http::assertNothingSent();
    }

    public function test_medreg_retrouve_le_veterinaire_au_meme_nom_accents_et_second_prenom_compris(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->postJson('/api/v1/inscription-clinique/gln', ['prenom' => 'lea', 'nom' => 'FONTAINE', 'gln' => '760 1000 1234 59'], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.verifie', true)
            ->assertJsonPath('data.raison', null)
            ->assertJsonPath('data.personne.nom', 'Fontaine')
            ->assertJsonPath('data.personne.autorisation', true)
            ->assertJsonPath('data.personne.cantons', ['Vaud']);
    }

    public function test_un_autre_nom_un_gln_inconnu_ou_medreg_en_panne_passent_a_la_main(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($jeton);

        $this->postJson('/api/v1/inscription-clinique/gln', ['prenom' => 'Paul', 'nom' => 'Martin', 'gln' => self::GLN], $entetes)
            ->assertOk()->assertJsonPath('data.verifie', false)->assertJsonPath('data.raison', 'nom_different');

        $this->postJson('/api/v1/inscription-clinique/gln', ['prenom' => 'Léa', 'nom' => 'Fontaine', 'gln' => self::AUTRE_GLN], $entetes)
            ->assertOk()->assertJsonPath('data.raison', 'introuvable')->assertJsonPath('data.personne', null);

        $this->medregEnPanne = true;
        $this->postJson('/api/v1/inscription-clinique/gln', ['prenom' => 'Léa', 'nom' => 'Fontaine', 'gln' => self::GLN], $entetes)
            ->assertOk()->assertJsonPath('data.raison', 'medreg_indisponible');
    }

    public function test_une_inscription_verifiee_cree_l_organisation_et_ouvre_une_session_sur_la_clinique_a_payer(): void
    {
        ['user' => $user, 'jeton' => $ancien] = $this->proprietaireAvecJeton();

        $reponse = $this->postJson('/api/v1/inscription-clinique', $this->inscription(), $this->enTetes($ancien))
            ->assertCreated()
            ->assertJsonPath('data.clinique.nom', 'Cabinet du Lac')
            ->assertJsonPath('data.employe.titre', 'Dr')
            ->assertJsonPath('data.organisation.verification', 'verifiee')
            ->assertJsonPath('data.organisation.fermeture', 'abonnement_requis')
            ->assertJsonPath('data.permissions', fn (array $domaines) => in_array('organisation', $domaines, true));

        $organisation = Organisation::query()->where('inscrite_par', $user->id)->firstOrFail();
        $this->assertSame('Cabinet du Lac', $organisation->nom);
        $this->assertNotNull($organisation->verifiee_le);
        $this->assertDatabaseHas('societes', ['organisation_id' => $organisation->id, 'nom' => 'Cabinet Fontaine Sàrl', 'ide' => 'CHE-123.456.789']);
        $clinique = $organisation->cliniques()->firstOrFail();
        $this->assertSame('1003', $clinique->npa);
        $this->assertDatabaseHas('horaires_ouverture', ['clinique_id' => $clinique->id]);
        $employe = Employe::withoutGlobalScopes()->where('user_id', $user->id)->with('role')->firstOrFail();
        $this->assertSame(RolesParDefaut::ADMINISTRATEUR, $employe->role->nom);
        $this->assertSame('Dr Léa Fontaine', $employe->nom);
        $this->assertSame(self::GLN, $user->refresh()->gln);
        $this->assertSame('Léa Fontaine', $user->name);
        Mail::assertNothingSent();

        // L'ancien jeton est révoqué, le nouveau porte la clinique
        $this->getJson('/api/v1/auth/moi', $this->enTetes($ancien))->assertUnauthorized();
        $entetes = $this->enTetes($reponse->json('data.token'));

        // Fermée tant qu'aucune formule n'est payée, sauf la facturation
        $this->getJson('/api/v1/patients', $entetes)->assertForbidden()->assertJsonPath('code', 'abonnement_requis');
        $this->getJson('/api/v1/organisation', $entetes)->assertOk()->assertJsonPath('data.verification', 'verifiee');

        $stripe = new PasserelleAbonnementFactice;
        $this->app->instance(PasserelleAbonnement::class, $stripe);
        $this->postJson('/api/v1/organisation/moyen-paiement/intention', [], $entetes)->assertOk();
        $this->putJson('/api/v1/organisation/moyen-paiement', ['payment_method' => $stripe->carteConfirmee($organisation->refresh()->stripe_client_id)], $entetes)->assertOk();
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'individuel'], $entetes)->assertOk();

        app(ContexteClinique::class)->oublier();
        $this->getJson('/api/v1/patients', $entetes)->assertOk();
        $this->getJson('/api/v1/auth/moi', $entetes)->assertJsonPath('data.organisation.fermeture', null);
    }

    public function test_une_inscription_non_verifiee_attend_la_plateforme_qui_valide_par_lien_signe(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();

        $entetes = $this->enTetes($this->postJson('/api/v1/inscription-clinique', $this->inscription(['prenom' => 'Paul', 'nom' => 'Martin']), $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.organisation.verification', 'en_attente')
            ->assertJsonPath('data.organisation.fermeture', 'verification_en_attente')
            ->json('data.token'));

        $organisation = Organisation::query()->where('inscrite_par', $user->id)->firstOrFail();
        $this->assertSame('nom_different', $organisation->verification_raison);
        $this->assertSame(self::GLN, $organisation->verification_gln);
        $this->assertNull($user->refresh()->gln);

        // Rien ne s'ouvre ni ne se paie avant la vérification
        $this->getJson('/api/v1/patients', $entetes)->assertForbidden()->assertJsonPath('code', 'verification_en_attente');
        $this->postJson('/api/v1/organisation/abonnement', ['plan' => 'individuel'], $entetes)
            ->assertUnprocessable()->assertJsonPath('errors.plan.0', 'Votre clinique doit d’abord être vérifiée.');

        $mail = null;
        Mail::assertSent(VerificationCliniqueAFaire::class, function (VerificationCliniqueAFaire $envoye) use (&$mail): bool {
            $mail = $envoye;

            return $envoye->hasTo('verification@plateforme.test');
        });

        // Le lien affiche une confirmation, sans rien décider
        $this->get($mail->lienValider)->assertOk()->assertSee('Valider la clinique')->assertSee('Paul Martin');
        $this->assertSame('en_attente', $organisation->refresh()->verification->value);

        $this->post($mail->lienValider)->assertOk()->assertSee('Clinique vérifiée');
        $this->assertSame('verifiee', $organisation->refresh()->verification->value);
        $this->assertSame(self::GLN, $user->refresh()->gln);
        Mail::assertSent(CliniqueVerifiee::class, fn (CliniqueVerifiee $envoye) => $envoye->hasTo($user->email));

        app(ContexteClinique::class)->oublier();
        $this->getJson('/api/v1/auth/moi', $entetes)->assertJsonPath('data.organisation.fermeture', 'abonnement_requis');
    }

    public function test_un_lien_de_verification_altere_ne_sert_a_rien(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->postJson('/api/v1/inscription-clinique', $this->inscription(['gln' => self::AUTRE_GLN]), $this->enTetes($jeton))->assertCreated();
        $organisation = Organisation::query()->where('inscrite_par', $user->id)->firstOrFail();

        $this->get("/verification-cliniques/{$organisation->id}/valider")->assertForbidden();
        $this->post("/verification-cliniques/{$organisation->id}/valider")->assertForbidden();

        Mail::assertSent(VerificationCliniqueAFaire::class, function (VerificationCliniqueAFaire $envoye): bool {
            $this->post($envoye->lienValider.'x')->assertForbidden();

            return true;
        });

        $this->assertSame('en_attente', $organisation->refresh()->verification->value);
    }

    public function test_un_refus_previent_le_veterinaire_avec_le_motif_et_reste_acquis(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $entetes = $this->enTetes($this->postJson('/api/v1/inscription-clinique', $this->inscription(['gln' => self::AUTRE_GLN]), $this->enTetes($jeton))->json('data.token'));
        $organisation = Organisation::query()->where('inscrite_par', $user->id)->firstOrFail();
        $this->assertSame('introuvable', $organisation->verification_raison);

        Mail::assertSent(VerificationCliniqueAFaire::class, function (VerificationCliniqueAFaire $envoye): bool {
            $this->post($envoye->lienRefuser, ['note' => 'Aucune autorisation de pratiquer à ce nom.'])->assertOk()->assertSee('Inscription refusée');
            // Une décision prise ne se défait pas par l'autre lien
            $this->post($envoye->lienValider)->assertOk()->assertSee('Inscription refusée');

            return true;
        });

        $organisation->refresh();
        $this->assertSame('refusee', $organisation->verification->value);
        $this->assertSame('Aucune autorisation de pratiquer à ce nom.', $organisation->verification_note);
        Mail::assertSent(CliniqueRefusee::class, fn (CliniqueRefusee $envoye) => $envoye->hasTo($user->email) && $envoye->hasReplyTo('verification@plateforme.test'));

        app(ContexteClinique::class)->oublier();
        $this->getJson('/api/v1/auth/moi', $entetes)
            ->assertJsonPath('data.organisation.fermeture', 'verification_refusee')
            ->assertJsonPath('data.organisation.verification_note', 'Aucune autorisation de pratiquer à ce nom.');
        $this->getJson('/api/v1/patients', $entetes)->assertForbidden()->assertJsonPath('code', 'verification_refusee');
    }

    public function test_une_seule_inscription_a_la_fois(): void
    {
        ['jeton' => $jeton] = $this->proprietaireAvecJeton();
        $nouveau = $this->postJson('/api/v1/inscription-clinique', $this->inscription(['gln' => self::AUTRE_GLN]), $this->enTetes($jeton))->json('data.token');

        $this->postJson('/api/v1/inscription-clinique', $this->inscription(), $this->enTetes($nouveau))
            ->assertUnprocessable()
            ->assertJsonPath('errors.inscription.0', 'Votre clinique « Cabinet du Lac » attend encore sa vérification.');
    }

    public function test_un_gln_deja_celui_d_un_autre_compte_passe_a_la_main_sans_interroger_medreg(): void
    {
        User::factory()->create()->forceFill(['gln' => self::GLN])->save();
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();

        $this->postJson('/api/v1/inscription-clinique', $this->inscription(), $this->enTetes($jeton))
            ->assertCreated()
            ->assertJsonPath('data.organisation.verification', 'en_attente');

        $this->assertSame('gln_deja_utilise', Organisation::query()->where('inscrite_par', $user->id)->value('verification_raison'));
        Http::assertNothingSent();
    }

    public function test_medreg_en_panne_a_l_inscription_la_clinique_se_verifie_des_son_retour(): void
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->medregEnPanne = true;
        $this->postJson('/api/v1/inscription-clinique', $this->inscription(), $this->enTetes($jeton))->assertCreated();
        $organisation = Organisation::query()->where('inscrite_par', $user->id)->firstOrFail();
        $this->assertSame('medreg_indisponible', $organisation->verification_raison);
        Mail::assertSent(VerificationCliniqueAFaire::class);

        $this->artisan('inscriptions:reessayer')->assertSuccessful();
        $this->assertSame('en_attente', $organisation->refresh()->verification->value);

        $this->medregEnPanne = false;
        $this->artisan('inscriptions:reessayer')->expectsOutput('1 clinique(s) vérifiée(s).')->assertSuccessful();
        $this->assertSame('verifiee', $organisation->refresh()->verification->value);
        $this->assertSame(self::GLN, $user->refresh()->gln);
        Mail::assertSent(CliniqueVerifiee::class);
    }

    public function test_les_cliniques_fermees_ne_paraissent_ni_dans_l_annuaire_ni_dans_les_boutiques(): void
    {
        $ouverte = Clinique::factory()->create(['nom' => 'Ouverte']);
        Clinique::factory()->for(Societe::factory()->for(Organisation::factory()->enAttente()))->create(['nom' => 'En attente']);
        Clinique::factory()->for(Societe::factory()->for(Organisation::factory()->sansAbonnement()))->create(['nom' => 'Sans abonnement']);

        $this->getJson('/api/v1/public/cliniques')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ouverte->id);
        $this->getJson('/api/v1/public/boutique')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ouverte->id);
    }
}
