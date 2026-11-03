<?php

namespace Tests\Feature\Patients;

use App\Auth\ContexteClinique;
use App\Auth\EmetteurJetons;
use App\Mail\OwnerSpaceInvitation;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Patient;
use App\Models\User;
use App\Notifications\CodeEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Créer un propriétaire, en groupes de lignes façon Réglages (choix de Jim,
 * 03.11.2026). Chaque bloc suit une case de notes/todo.md du web.
 */
class OwnerCreationTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    private function organisationId(): int
    {
        return (int) $this->clinique->societe->organisation_id;
    }

    /** @param  array<string, mixed>  $donnees */
    private function creer(array $donnees): TestResponse
    {
        return $this->postJson('/api/v1/clients', ['prenom' => 'Lena', 'nom' => 'Rochat', ...$donnees], $this->enTetes($this->jeton));
    }

    // ————— Case 1 : groupes Identité, Contact, Rappels —————

    public function test_le_contact_d_urgence_et_les_rappels_se_posent_a_la_creation(): void
    {
        $id = $this->creer([
            'civilite' => 'Mme', 'tel' => '079 321 54 76', 'email' => 'lena.rochat@bluewin.ch',
            'rue' => 'Rue du Lac 4', 'npa' => '1020', 'localite' => 'Renens VD',
            'emergency_contact' => 'Paul Rochat, son frère', 'emergency_phone' => '078 111 22 33',
            'rappels_sms' => true, 'rappels_email' => false,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('clients', [
            'id' => $id, 'emergency_contact' => 'Paul Rochat, son frère', 'emergency_phone' => '078 111 22 33',
            'rappels_sms' => true, 'rappels_email' => false, 'user_id' => null,
        ]);
    }

    // ————— Case 2 : un mobile déjà connu arrête la création —————

    public function test_un_mobile_deja_connu_arrete_la_creation_quel_que_soit_son_format(): void
    {
        Client::factory()->create(['organisation_id' => $this->organisationId(), 'civilite' => 'Mme', 'prenom' => 'Claire', 'nom' => 'Borel', 'tel' => '079 123 45 67']);

        foreach (['079 123 45 67', '+41 79 123 45 67', '0041791234567', '0791234567'] as $numero) {
            $this->creer(['tel' => $numero])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['tel' => 'Ce mobile est déjà celui de Mme Claire Borel.']);
        }
        $this->assertSame(1, Client::query()->count());
    }

    public function test_le_meme_mobile_dans_une_autre_organisation_ne_bloque_pas_et_ne_se_dit_pas(): void
    {
        $ailleurs = Clinique::factory()->create();
        Client::factory()->create(['organisation_id' => $ailleurs->societe->organisation_id, 'tel' => '079 123 45 67', 'prenom' => 'Secret', 'nom' => 'Ailleurs']);

        $this->creer(['tel' => '+41 79 123 45 67'])->assertCreated();
        $this->getJson('/api/v1/clients?tel=0791234567', $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonMissing(['nom' => 'Ailleurs'])
            ->assertJsonCount(1, 'data');
    }

    public function test_la_liste_trouve_le_client_d_un_mobile_ecrit_autrement(): void
    {
        $claire = Client::factory()->create(['organisation_id' => $this->organisationId(), 'tel' => '079 123 45 67']);
        Client::factory()->create(['organisation_id' => $this->organisationId(), 'tel' => '079 765 43 21']);

        $this->getJson('/api/v1/clients?tel='.urlencode('+41 79 123 45 67'), $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $claire->id);
    }

    public function test_un_numero_fixe_partage_par_une_famille_ne_bloque_pas(): void
    {
        Client::factory()->create(['organisation_id' => $this->organisationId(), 'tel' => '021 728 40 12']);

        $this->creer(['tel' => '+41 21 728 40 12'])->assertCreated();
    }

    // ————— Case 5 : inviter dans son espace, un e-mail part —————
    // Rien n'est rattaché à la création (vérification du 03.11.2026) : une
    // invitation attend sur la fiche ; la personne la prend une fois connectée
    // avec l'adresse invitée (code e-mail), dans les 30 jours, une fois.

    /** Crée une fiche avec invitation ; rend la fiche et le jeton du lien envoyé. */
    private function inviter(string $email = 'Lena.Rochat@Bluewin.ch'): array
    {
        Mail::fake();
        $id = $this->creer(['email' => $email, 'invite_to_space' => true])->assertCreated()->json('data.id');
        $jeton = null;
        Mail::assertQueued(OwnerSpaceInvitation::class, function (OwnerSpaceInvitation $mail) use (&$jeton): bool {
            $jeton = $mail->token;

            return true;
        });
        app(ContexteClinique::class)->oublier();

        return [Client::query()->withoutGlobalScopes()->findOrFail($id), (string) $jeton];
    }

    private function accepter(string $jeton, string $email, array $attributs = []): TestResponse
    {
        $compte = User::query()->where('email', $email)->first();
        $session = $compte === null
            ? $this->proprietaireAvecJeton(['email' => $email, ...$attributs])['jeton']
            : app(EmetteurJetons::class)->emettreSansClinique($compte)->plainTextToken;

        return $this->postJson('/api/v1/espace/invitations-fiche/accepter', ['jeton' => $jeton], $this->enTetes($session));
    }

    public function test_inviter_ne_rattache_rien_et_envoie_un_lien_a_usage_unique(): void
    {
        [$fiche, $jeton] = $this->inviter();

        $this->assertNull($fiche->user_id);
        $this->assertSame(0, User::query()->where('email', 'lena.rochat@bluewin.ch')->count());
        $this->assertSame('lena.rochat@bluewin.ch', $fiche->space_invitation_email);
        $this->assertSame(hash('sha256', $jeton), $fiche->space_invitation_token_hash);
        $this->assertGreaterThanOrEqual(40, strlen($jeton));

        Mail::assertQueued(OwnerSpaceInvitation::class, function (OwnerSpaceInvitation $mail) use ($jeton): bool {
            $html = $mail->render();

            return $mail->hasTo('lena.rochat@bluewin.ch')
                && str_contains($html, e($this->clinique->nom).' a créé votre fiche')
                && ! str_contains($html, 'societe_id')
                && str_contains($html, '/espace/invitation#jeton='.$jeton)
                && str_contains($html, 'Ignorez cet e-mail : rien ne se passera sans vous');
        });
    }

    public function test_la_creation_ne_dit_pas_si_l_adresse_a_un_compte_et_ne_lui_emprunte_rien(): void
    {
        $existant = User::factory()->create(['email' => 'deja@exemple.test', 'rue' => 'Rue du Compte 9', 'npa' => '1000', 'localite' => 'Lausanne 25', 'tel' => '079 888 77 66']);
        $comptes = User::query()->count();
        Mail::fake();

        $avec = $this->creer(['prenom' => 'Ana', 'email' => 'deja@exemple.test', 'invite_to_space' => true])->assertCreated()->json('data');
        $sans = $this->creer(['prenom' => 'Ana', 'email' => 'jamais@exemple.test', 'invite_to_space' => true])->assertCreated()->json('data');

        // L'adresse invitée et l'heure d'envoi diffèrent forcément ; leur forme est la même
        $this->assertSame(array_keys($sans['space_invitation']), array_keys($avec['space_invitation']));
        $sansIdentite = fn (array $fiche): array => collect($fiche)->except(['id', 'email', 'updated_at', 'space_invitation'])->all();
        $this->assertSame($sansIdentite($sans), $sansIdentite($avec));
        $this->assertSame($comptes, User::query()->count());
        $this->assertDatabaseHas('clients', ['id' => $avec['id'], 'user_id' => null, 'rue' => null, 'npa' => null, 'localite' => null, 'tel' => null]);
        $this->assertNotSame($existant->id, Client::query()->findOrFail($avec['id'])->user_id);
    }

    public function test_l_invitation_exige_un_e_mail(): void
    {
        Mail::fake();

        $this->creer(['invite_to_space' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Pour l’inviter dans son espace, il faut son e-mail.']);
        $this->assertSame(0, Client::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_sans_invitation_aucun_compte_ni_e_mail(): void
    {
        Mail::fake();

        $id = $this->creer(['email' => 'lena.rochat@bluewin.ch'])->assertCreated()->assertJsonPath('data.a_un_compte', false)->json('data.id');
        $this->assertSame(0, User::query()->where('email', 'lena.rochat@bluewin.ch')->count());
        $this->assertNull(Client::query()->findOrFail($id)->space_invitation_token_hash);
        Mail::assertNothingQueued();
    }

    /** Le mail part une fois la fiche enregistrée : une transaction défaite n'envoie rien. */
    public function test_le_mail_ne_part_qu_apres_l_enregistrement(): void
    {
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $envoyes = fn (): int => count($transport->messages());

        try {
            DB::transaction(function (): void {
                $this->creer(['email' => 'lena.rochat@bluewin.ch', 'invite_to_space' => true])->assertCreated();
                throw new \RuntimeException('Enregistrement défait');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, Client::query()->count());
        $this->assertSame(0, $envoyes());

        $this->creer(['email' => 'lena.rochat@bluewin.ch', 'invite_to_space' => true])->assertCreated();
        $this->assertSame(1, $envoyes());
    }

    public function test_la_bonne_adresse_accepte_et_la_fiche_est_rattachee(): void
    {
        [$fiche, $jeton] = $this->inviter();

        $this->accepter($jeton, 'lena.rochat@bluewin.ch')
            ->assertOk()
            ->assertJsonPath('data.clinique', $this->clinique->nom);

        $fiche->refresh();
        $this->assertSame(User::query()->where('email', 'lena.rochat@bluewin.ch')->value('id'), $fiche->user_id);
        $this->assertNull($fiche->space_invitation_token_hash);
    }

    public function test_une_autre_adresse_est_refusee(): void
    {
        [$fiche, $jeton] = $this->inviter();

        $this->accepter($jeton, 'quelqu.un@exemple.test')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invitation' => 'Connectez-vous avec l’adresse qui a reçu l’invitation.']);
        $this->assertNull($fiche->refresh()->user_id);
        $this->assertNotNull($fiche->space_invitation_token_hash);
    }

    public function test_une_adresse_non_prouvee_est_refusee(): void
    {
        [$fiche, $jeton] = $this->inviter();

        $this->accepter($jeton, 'lena.rochat@bluewin.ch', ['email_verified_at' => null])->assertUnprocessable();
        $this->assertNull($fiche->refresh()->user_id);
    }

    public function test_un_lien_expire_est_refuse(): void
    {
        [$fiche, $jeton] = $this->inviter();
        $this->travel(31)->days();

        $this->accepter($jeton, 'lena.rochat@bluewin.ch')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invitation' => 'Ce lien a expiré : demandez une nouvelle invitation à la clinique.']);
        $this->assertNull($fiche->refresh()->user_id);
    }

    public function test_un_lien_deja_utilise_ou_inconnu_est_refuse(): void
    {
        [, $jeton] = $this->inviter();
        $this->accepter($jeton, 'lena.rochat@bluewin.ch')->assertOk();

        $this->accepter($jeton, 'lena.rochat@bluewin.ch')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invitation' => 'Ce lien n’est plus valable : demandez une nouvelle invitation à la clinique.']);
        $this->accepter(str_repeat('a', 48), 'lena.rochat@bluewin.ch')->assertUnprocessable()->assertJsonValidationErrors('invitation');
    }

    public function test_un_animal_reclame_par_un_autre_compte_bloque_le_rattachement(): void
    {
        [$fiche, $jeton] = $this->inviter();
        $autre = User::factory()->create();
        Patient::factory()->for($fiche)->create(['proprietaire_id' => $autre->id]);

        $this->accepter($jeton, 'lena.rochat@bluewin.ch')->assertUnprocessable()->assertJsonValidationErrors('client');
        $this->assertNull($fiche->refresh()->user_id);
    }

    /** Parcours entier : pas encore de compte ; il se connecte par le code reçu, puis accepte. */
    public function test_la_personne_invitee_se_connecte_par_code_puis_accepte(): void
    {
        [$fiche, $jeton] = $this->inviter();
        Notification::fake();

        $this->postJson('/api/v1/auth/code-email/envoyer', ['email' => 'lena.rochat@bluewin.ch'])->assertStatus(202);
        $code = null;
        Notification::assertSentOnDemand(CodeEmailNotification::class, function (CodeEmailNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });
        $session = $this->postJson('/api/v1/auth/code-email/verifier', ['email' => 'lena.rochat@bluewin.ch', 'code' => $code])
            ->assertOk()
            ->json('data.token');

        $this->postJson('/api/v1/espace/invitations-fiche/accepter', ['jeton' => $jeton], $this->enTetes($session))->assertOk();
        $this->assertSame(User::query()->where('email', 'lena.rochat@bluewin.ch')->value('id'), $fiche->refresh()->user_id);
    }

    // ————— Une invitation partie s'annule (deuxième vérification, 03.11.2026) —————

    private function staff(): array
    {
        app(ContexteClinique::class)->oublier();

        return $this->enTetes($this->jeton);
    }

    public function test_changer_l_e_mail_de_la_fiche_annule_l_invitation(): void
    {
        [$fiche, $jeton] = $this->inviter();

        $this->patchJson("/api/v1/clients/{$fiche->id}", ['email' => 'nouvelle@exemple.test'], $this->staff())->assertOk();

        $this->assertNull($fiche->refresh()->space_invitation_token_hash);
        $this->accepter($jeton, 'lena.rochat@bluewin.ch')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invitation' => 'Ce lien n’est plus valable : demandez une nouvelle invitation à la clinique.']);
        $this->assertNull($fiche->refresh()->user_id);
    }

    public function test_la_meme_adresse_ecrite_autrement_ne_l_annule_pas(): void
    {
        [$fiche] = $this->inviter();

        $this->patchJson("/api/v1/clients/{$fiche->id}", ['email' => 'LENA.ROCHAT@bluewin.ch'], $this->staff())->assertOk();

        $this->assertNotNull($fiche->refresh()->space_invitation_token_hash);
    }

    public function test_ouvrir_puis_retirer_un_compte_annule_l_invitation(): void
    {
        [$fiche, $jeton] = $this->inviter();

        $this->postJson("/api/v1/clients/{$fiche->id}/compte", [], $this->staff())->assertOk();
        $this->assertNull($fiche->refresh()->space_invitation_token_hash);
        $this->deleteJson("/api/v1/clients/{$fiche->id}/compte", [], $this->staff())->assertNoContent();

        $this->accepter($jeton, 'lena.rochat@bluewin.ch')->assertUnprocessable()->assertJsonValidationErrors('invitation');
        $this->assertNull($fiche->refresh()->user_id);
    }

    public function test_l_adresse_invitee_doit_etre_encore_celle_de_la_fiche(): void
    {
        [$fiche, $jeton] = $this->inviter();
        // Une adresse changée sans passer par le modèle (import, console)
        DB::table('clients')->where('id', $fiche->id)->update(['email' => 'autre@exemple.test']);

        $this->accepter($jeton, 'lena.rochat@bluewin.ch')->assertUnprocessable()->assertJsonValidationErrors('invitation');
        $this->assertNull($fiche->refresh()->user_id);
    }

    public function test_la_clinique_voit_l_invitation_en_attente_sans_le_jeton(): void
    {
        [$fiche, $jeton] = $this->inviter();

        $reponse = $this->getJson("/api/v1/clients/{$fiche->id}", $this->staff())
            ->assertOk()
            ->assertJsonPath('data.space_invitation.email', 'lena.rochat@bluewin.ch')
            ->assertJsonPath('data.space_invitation.sent_at', $fiche->space_invitation_sent_at->toIso8601String());
        $this->assertStringNotContainsString($jeton, $reponse->getContent());
        $this->assertStringNotContainsString((string) $fiche->space_invitation_token_hash, $reponse->getContent());
        $this->assertStringNotContainsString('token', $reponse->getContent());
    }

    public function test_renvoyer_donne_un_nouveau_lien_et_l_ancien_ne_vaut_plus_rien(): void
    {
        [$fiche, $ancien] = $this->inviter();
        Mail::fake();

        $this->postJson("/api/v1/clients/{$fiche->id}/invitation", [], $this->staff())
            ->assertOk()
            ->assertJsonPath('data.space_invitation.email', 'lena.rochat@bluewin.ch');
        $nouveau = null;
        Mail::assertQueued(OwnerSpaceInvitation::class, function (OwnerSpaceInvitation $mail) use (&$nouveau): bool {
            $nouveau = $mail->token;

            return $mail->hasTo('lena.rochat@bluewin.ch');
        });
        $this->assertNotSame($ancien, $nouveau);

        $this->accepter($ancien, 'lena.rochat@bluewin.ch')->assertUnprocessable();
        $this->accepter((string) $nouveau, 'lena.rochat@bluewin.ch')->assertOk();
    }

    /** Le renvoi relit la fiche sous verrou : une adresse corrigée entre-temps reçoit le lien, pas l'ancienne */
    public function test_renvoyer_relit_la_fiche_avant_d_envoyer(): void
    {
        [$fiche] = $this->inviter();
        Mail::fake();
        // Une écriture glissée entre la lecture de la route et l'envoi
        Gate::before(function () use ($fiche) {
            DB::table('clients')->where('id', $fiche->id)->update(['email' => 'corrigee@exemple.test']);

            return null;
        });

        $this->postJson("/api/v1/clients/{$fiche->id}/invitation", [], $this->staff())
            ->assertOk()
            ->assertJsonPath('data.space_invitation.email', 'corrigee@exemple.test');
        Mail::assertQueued(OwnerSpaceInvitation::class, fn (OwnerSpaceInvitation $mail): bool => $mail->hasTo('corrigee@exemple.test'));
        Mail::assertNotQueued(OwnerSpaceInvitation::class, fn (OwnerSpaceInvitation $mail): bool => $mail->hasTo('lena.rochat@bluewin.ch'));
    }

    /** Après 30 jours, la clinique voit que le lien a expiré */
    public function test_une_invitation_expiree_se_dit_a_la_clinique(): void
    {
        [$fiche] = $this->inviter();

        $this->travel(29)->days();
        $this->getJson("/api/v1/clients/{$fiche->id}", $this->staff())->assertOk()->assertJsonPath('data.space_invitation.is_expired', false);
        $this->travel(2)->days();
        $this->getJson("/api/v1/clients/{$fiche->id}", $this->staff())->assertOk()->assertJsonPath('data.space_invitation.is_expired', true);
    }

    /** Un lien coupé par la messagerie : un message clair, en français */
    public function test_un_lien_coupe_le_dit_en_francais(): void
    {
        $this->accepter('abc', 'lena.rochat@bluewin.ch')
            ->assertUnprocessable()
            ->assertJsonPath('errors.jeton.0', 'Ce lien est incomplet : ouvrez de nouveau le lien reçu par e-mail.');
    }

    public function test_annuler_l_invitation(): void
    {
        [$fiche, $jeton] = $this->inviter();

        $this->deleteJson("/api/v1/clients/{$fiche->id}/invitation", [], $this->staff())
            ->assertOk()
            ->assertJsonPath('data.space_invitation', null);
        $this->accepter($jeton, 'lena.rochat@bluewin.ch')->assertUnprocessable();
    }

    public function test_renvoyer_exige_un_e_mail_et_une_fiche_sans_compte(): void
    {
        $sansEmail = Client::factory()->create(['organisation_id' => $this->organisationId(), 'email' => null]);
        $avecCompte = Client::factory()->create(['organisation_id' => $this->organisationId(), 'email' => 'deja@exemple.test', 'user_id' => User::factory()->create()->id]);

        $this->postJson("/api/v1/clients/{$sansEmail->id}/invitation", [], $this->staff())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson("/api/v1/clients/{$avecCompte->id}/invitation", [], $this->staff())->assertUnprocessable()->assertJsonValidationErrors('client');
    }

    public function test_renvoyer_et_annuler_sont_reserves_a_l_equipe_de_l_organisation_avec_le_droit_patients(): void
    {
        $ailleurs = Client::factory()->create(['organisation_id' => Clinique::factory()->create()->societe->organisation_id, 'email' => 'x@exemple.test']);
        $this->postJson("/api/v1/clients/{$ailleurs->id}/invitation", [], $this->staff())->assertNotFound();
        $this->deleteJson("/api/v1/clients/{$ailleurs->id}/invitation", [], $this->staff())->assertNotFound();

        $fiche = Client::factory()->create(['organisation_id' => $this->organisationId(), 'email' => 'y@exemple.test']);
        ['employe' => $employe, 'jeton' => $sansDroit] = $this->employeAvecJeton($this->clinique);
        $employe->update(['role_id' => null]);
        $this->postJson("/api/v1/clients/{$fiche->id}/invitation", [], $this->enTetes($sansDroit))->assertForbidden();
        $this->deleteJson("/api/v1/clients/{$fiche->id}/invitation", [], $this->enTetes($sansDroit))->assertForbidden();

        ['jeton' => $proprietaire] = $this->proprietaireAvecJeton();
        $this->assertContains($this->postJson("/api/v1/clients/{$fiche->id}/invitation", [], $this->enTetes($proprietaire))->status(), [403]);
    }

    public function test_le_jeton_part_dans_le_corps_et_garde_sa_forme(): void
    {
        [, $jeton] = $this->inviter();
        ['jeton' => $session] = $this->proprietaireAvecJeton(['email' => 'lena.rochat@bluewin.ch']);

        $this->postJson("/api/v1/espace/invitations-fiche/{$jeton}/accepter", [], $this->enTetes($session))->assertNotFound();
        foreach (['', 'court', str_repeat('a', 47), str_repeat('a', 49), str_repeat('é', 48), str_repeat('a', 47).'-'] as $mauvais) {
            $this->postJson('/api/v1/espace/invitations-fiche/accepter', ['jeton' => $mauvais], $this->enTetes($session))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('jeton');
        }
    }

    /** Le mail attend dans la file chiffré : le jeton n'y est jamais en clair. */
    public function test_le_mail_attend_dans_la_file_chiffre(): void
    {
        config(['queue.default' => 'database']);

        $this->creer(['email' => 'lena.rochat@bluewin.ch', 'invite_to_space' => true])->assertCreated();

        $brut = (string) DB::table('jobs')->value('payload');
        $commande = json_decode($brut, true)['data']['command'];
        $mail = unserialize(Crypt::decrypt($commande))->mailable;
        $this->assertInstanceOf(OwnerSpaceInvitation::class, $mail);
        $this->assertSame(48, strlen($mail->token));
        $this->assertStringNotContainsString($mail->token, $brut);
    }

    /** Sans clinique, aucune invitation ne peut partir : 422, et rien n'est créé. */
    public function test_l_invitation_sans_clinique_est_refusee(): void
    {
        Mail::fake();
        $this->app->instance(ContexteClinique::class, new class extends ContexteClinique
        {
            public function clinique(): ?Clinique
            {
                return null;
            }
        });

        $this->creer(['email' => 'lena.rochat@bluewin.ch', 'invite_to_space' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invite_to_space');
        $this->assertSame(0, Client::query()->withoutGlobalScopes()->count());
        Mail::assertNothingQueued();
    }
}
