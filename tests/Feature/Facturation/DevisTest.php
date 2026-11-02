<?php

namespace Tests\Feature\Facturation;

use App\Jobs\SendPush;
use App\Mail\DevisEnvoye;
use App\Mail\RelanceDevis;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Cycle de vie d'un devis (docs/data-model.md §7) : brouillon (seul
 * modifiable et supprimable) → envoi (canal selon le compte espace
 * client) → acceptation signée / refus ; l'expiration est dérivée de la
 * validité ; l'acompte puis la facture finale déduisent remise et
 * acompte en lignes négatives.
 */
class DevisTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $patient;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinique = Clinique::factory()->create();
        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create();

        ['jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
    }

    /** Un brouillon reçoit une prestation : sans elle il ne s'envoie pas (01.11.2026) */
    private function devis(array $attributs = []): Devis
    {
        $devis = Devis::factory()->create([
            'organisation_id' => $this->clinique->societe->organisation_id,
            'patient_id' => $this->patient->id,
            ...$attributs,
        ]);
        if (($attributs['statut'] ?? 'brouillon') === 'brouillon') {
            DevisLigne::factory()->create(['devis_id' => $devis->id]);
        }

        return $devis;
    }

    public function test_creer_un_devis_numerote_en_brouillon_et_derive_les_totaux(): void
    {
        $annee = aujourdhui()->year;

        $this->postJson('/api/v1/facturation/devis', [
            'patient_id' => $this->patient->id,
            'motif' => 'Ostéosynthèse fémur droit',
            'lignes' => [
                ['libelle' => 'Chirurgie : forfait bloc', 'quantite' => 1, 'prix_chf' => 420, 'type' => 'acte'],
                ['libelle' => 'Radiographie : 2 clichés', 'quantite' => 2, 'prix_chf' => 120, 'type' => 'acte'],
            ],
            'remise_pct' => 10,
            'acompte_pct' => 30,
        ], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.numero', "DV-{$annee}-001")
            ->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.statut_effectif', 'brouillon')
            ->assertJsonPath('data.sous_total', 660)
            ->assertJsonPath('data.total_ttc', 594)
            // TVA 8.1 % extraite du TTC remisé, comme sur la facture.
            ->assertJsonPath('data.montant_tva', 44.51)
            // Établi par la clinique du jeton, son en-tête sur les pièces
            ->assertJsonPath('data.clinique_id', $this->clinique->id)
            ->assertJsonPath('data.en_tete.nom', $this->clinique->raison_sociale ?: $this->clinique->nom)
            ->assertJsonPath('data.montant_remise', 66)
            ->assertJsonPath('data.montant_acompte', 178.2);
    }

    /** Un brouillon se commence sans patient ni prestation (01.11.2026, demande de Jim) ; il ne part pas ainsi. */
    public function test_un_brouillon_se_commence_sans_patient_mais_ne_part_pas_sans_lui(): void
    {
        $id = $this->postJson('/api/v1/facturation/devis', ['motif' => 'Détartrage', 'lignes' => []], $this->enTetes($this->jeton))
            ->assertCreated()
            ->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.patient_id', null)
            ->assertJsonPath('data.total_ttc', 0)
            ->json('data.id');

        $this->postJson("/api/v1/facturation/devis/{$id}/envoyer", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id' => 'Choisissez le patient avant d’envoyer le devis.']);

        $this->patchJson("/api/v1/facturation/devis/{$id}", ['patient_id' => $this->patient->id], $this->enTetes($this->jeton))->assertOk();
        $this->postJson("/api/v1/facturation/devis/{$id}/envoyer", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes' => 'Ajoutez au moins une prestation avant d’envoyer le devis.']);

        $this->patchJson("/api/v1/facturation/devis/{$id}", ['lignes' => [['libelle' => 'Détartrage', 'prix_chf' => 180, 'type' => 'acte']]], $this->enTetes($this->jeton))->assertOk();
        $this->postJson("/api/v1/facturation/devis/{$id}/envoyer", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'envoye');

        // Le patient se retire d'un brouillon, aussi
        $brouillon = $this->devis();
        $this->patchJson("/api/v1/facturation/devis/{$brouillon->id}", ['patient_id' => null], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.patient_id', null);
    }

    /** Le formulaire chiffré par le serveur avant l'enregistrement : les mêmes montants que le devis enregistré (24.10.2026). */
    public function test_l_apercu_du_formulaire_donne_les_montants_du_devis_enregistre(): void
    {
        $saisie = [
            'lignes' => [
                ['libelle' => 'Détartrage', 'quantite' => 2, 'prix_chf' => 100, 'type' => 'acte'],
                ['libelle' => 'Anesthésie', 'quantite' => 1, 'prix_chf' => 50.5, 'type' => 'acte'],
            ],
            'remise_pct' => 10,
            'alea_pct' => 20,
            'acompte_pct' => 30,
        ];

        $apercu = $this->postJson('/api/v1/facturation/devis/apercu', $saisie, $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.lignes.0.total_chf', 200)
            ->assertJsonPath('data.lignes.1.total_chf', 50.5)
            ->assertJsonPath('data.sous_total', 250.5)
            ->assertJsonPath('data.total_ttc', 225.45)
            ->assertJsonPath('data.montant_remise', 25.05)
            ->assertJsonPath('data.total_avec_alea', 270.54)
            ->json('data');
        $this->assertDatabaseCount('devis', 0);

        $enregistre = $this->postJson('/api/v1/facturation/devis', ['patient_id' => $this->patient->id, ...$saisie], $this->enTetes($this->jeton))
            ->assertCreated()->json('data');
        foreach (['sous_total', 'montant_remise', 'total_ttc', 'montant_tva', 'total_ht', 'total_avec_alea', 'montant_acompte', 'taux_tva'] as $cle) {
            $this->assertEquals($enregistre[$cle], $apercu[$cle], $cle);
        }
    }

    public function test_l_envoi_pose_la_validite_et_choisit_le_portail_quand_le_proprietaire_a_un_compte(): void
    {
        $this->patient->client->update(['user_id' => User::factory()->create()->id]);
        $devis = $this->devis(['validite_jours' => 30]);

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'envoye')
            ->assertJsonPath('data.canal', 'portail')
            ->assertJsonPath('data.valide_jusquau', aujourdhui()->addDays(30)->toDateString());
    }

    /** Notification du téléphone (02.11.2026) : un devis envoyé sur l'espace client prévient le propriétaire ; un devis papier, personne. */
    public function test_un_devis_envoye_sur_l_espace_client_previent_le_proprietaire(): void
    {
        Queue::fake([SendPush::class]);
        $papier = $this->devis();
        $this->postJson("/api/v1/facturation/devis/{$papier->id}/envoyer", [], $this->enTetes($this->jeton))->assertOk();
        Queue::assertNotPushed(SendPush::class);

        $compte = User::factory()->create();
        $this->patient->client->update(['user_id' => $compte->id]);
        $devis = $this->devis();
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $this->enTetes($this->jeton))->assertOk();

        Queue::assertPushed(SendPush::class, fn (SendPush $j): bool => $j->userId === $compte->id && $j->type === 'quote'
            && $j->body === 'Devis reçu pour '.$this->patient->nom && $j->url === '/espace/documents?devis='.$devis->id);
    }

    /** Relance d'un devis envoyé : sur l'espace client, un e-mail part (une fois par jour au plus) ; un devis papier se relance par téléphone. */
    public function test_la_relance_d_un_devis_previent_le_client(): void
    {
        Mail::fake();
        $compte = User::factory()->create(['email' => 'lea@exemple.ch']);
        $this->patient->client->update(['user_id' => $compte->id]);
        $devis = $this->devis();
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/relancer", [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['statut' => 'Seul un devis envoyé, encore valable, se relance.']);
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $entetes)->assertOk();

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/relancer", [], $entetes)->assertOk()->assertJsonPath('data.relance', 'email');
        Mail::assertSent(RelanceDevis::class, fn (RelanceDevis $mail) => $mail->hasTo('lea@exemple.ch') && $mail->devis->is($devis));
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/relancer", [], $entetes)
            ->assertUnprocessable()->assertJsonValidationErrors(['relance' => 'Ce devis a déjà été relancé aujourd’hui.']);
        Mail::assertSent(RelanceDevis::class, 1);

        $papier = $this->devis();
        $this->patient->client->update(['user_id' => null]);
        $this->postJson("/api/v1/facturation/devis/{$papier->id}/envoyer", [], $entetes)->assertOk();
        $this->postJson("/api/v1/facturation/devis/{$papier->id}/relancer", [], $entetes)->assertOk()->assertJsonPath('data.relance', 'telephone');
        Mail::assertSent(RelanceDevis::class, 1);
    }

    public function test_l_envoi_passe_au_papier_sans_compte_espace_client(): void
    {
        Mail::fake();
        $devis = $this->devis();

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.canal', 'papier')
            ->assertJsonPath('meta.email', null);
        Mail::assertNothingSent();
    }

    /** Sur l'espace client, l'envoi prévient le propriétaire par e-mail (31.10.2026) ; le suivi garde la date. */
    public function test_l_envoi_sur_l_espace_client_previent_le_proprietaire_par_e_mail(): void
    {
        Mail::fake();
        $this->patient->client->update(['user_id' => User::factory()->create(['email' => 'lea@exemple.ch'])->id]);
        $devis = $this->devis();

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('meta.email', 'envoye')
            ->assertJsonPath('data.canal', 'portail');
        Mail::assertSent(DevisEnvoye::class, fn (DevisEnvoye $mail) => $mail->hasTo('lea@exemple.ch') && $mail->devis->is($devis));
        $this->assertNotNull($devis->refresh()->envoye_le);
        $this->assertStringContainsString('/espace/documents?devis='.$devis->id, (new DevisEnvoye($devis))->render());
    }

    /** L'objet du devis s'écrit tel quel dans l'e-mail : ni lien ni gras fabriqués, ni signes effacés. */
    public function test_l_objet_du_devis_s_ecrit_tel_quel_dans_l_e_mail(): void
    {
        $devis = $this->devis(['motif' => 'Détartrage (3 dents) *urgent* [voir](https://exemple.ch)']);

        $html = (new DevisEnvoye($devis->load(['clinique', 'patient'])))->render();

        $this->assertStringContainsString('Détartrage (3 dents) *urgent* [voir](https://exemple.ch)', $html);
        $this->assertStringNotContainsString('<em>urgent</em>', $html);
        $this->assertStringNotContainsString('href="https://exemple.ch"', $html);
    }

    /** L'équipe enregistre un accord pendant que le client refuse en ligne : la réponse du client reste. */
    public function test_l_accord_de_l_equipe_n_ecrase_pas_la_reponse_du_client(): void
    {
        $devis = $this->devis(['statut' => 'envoye', 'canal' => 'portail', 'valide_jusquau' => aujourdhui()->addDays(30)]);
        $fait = false;
        DB::listen(function ($requete) use (&$fait, $devis): void {
            if (! $fait && str_starts_with(strtolower($requete->sql), 'select') && preg_match('/from [`"]devis[`"]/', $requete->sql) === 1) {
                $fait = true;
                DB::table('devis')->where('id', $devis->id)->update(['statut' => 'refuse', 'refuse_le' => now(), 'refuse_par' => 'Léa Martin, depuis son espace client']);
            }
        });

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/accepter", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['statut' => 'Le client vient de répondre à ce devis : rechargez-le.']);
        $this->assertSame('refuse', $devis->refresh()->statut->value);
        $this->assertNull($devis->signe_le);
    }

    /** L'e-mail ne part pas : le devis reste envoyé, la réponse le dit pour que l'équipe relance. */
    public function test_un_e_mail_qui_ne_part_pas_ne_bloque_pas_l_envoi(): void
    {
        $this->patient->client->update(['user_id' => User::factory()->create()->id]);
        $devis = $this->devis();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP indisponible'));

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'envoye')
            ->assertJsonPath('meta.email', 'echec');
    }

    /** La relance qui ne part pas ne s'inscrit pas au suivi. */
    public function test_une_relance_qui_ne_part_pas_ne_s_inscrit_pas(): void
    {
        $this->patient->client->update(['user_id' => User::factory()->create()->id]);
        $devis = $this->devis(['statut' => 'envoye', 'canal' => 'portail', 'valide_jusquau' => aujourdhui()->addDays(30)]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP indisponible'));

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/relancer", [], $this->enTetes($this->jeton))->assertStatus(503);
        $this->assertNull($devis->refresh()->relance_le);
    }

    public function test_la_relance_et_le_refus_de_l_equipe_s_inscrivent_au_suivi(): void
    {
        Mail::fake();
        $this->patient->client->update(['user_id' => User::factory()->create()->id]);
        $devis = $this->devis();
        $entetes = $this->enTetes($this->jeton);
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $entetes)->assertOk();
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/relancer", [], $entetes)->assertOk();
        $this->assertNotNull($devis->refresh()->relance_le);

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/refuser", [], $entetes)
            ->assertOk()
            ->assertJsonPath('data.statut', 'refuse');
        $devis->refresh();
        $this->assertNotNull($devis->refuse_le);
        $this->assertStringStartsWith('Refus enregistré par ', $devis->refuse_par);
    }

    public function test_seul_un_brouillon_se_modifie(): void
    {
        $envoye = $this->devis()->fresh();
        $this->postJson("/api/v1/facturation/devis/{$envoye->id}/envoyer", [], $this->enTetes($this->jeton))->assertOk();

        $this->patchJson("/api/v1/facturation/devis/{$envoye->id}", ['motif' => 'Modifié'], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['statut' => 'Seul un brouillon se modifie.']);

        $brouillon = $this->devis();
        DevisLigne::factory()->create(['devis_id' => $brouillon->id]);

        $this->patchJson("/api/v1/facturation/devis/{$brouillon->id}", [
            'motif' => 'Bilan complet',
            'lignes' => [['libelle' => 'Consultation standard', 'prix_chf' => 85, 'type' => 'acte']],
        ], $this->enTetes($this->jeton))
            ->assertOk()
            ->assertJsonPath('data.motif', 'Bilan complet')
            ->assertJsonCount(1, 'data.lignes')
            ->assertJsonPath('data.lignes.0.libelle', 'Consultation standard');
    }

    public function test_l_acceptation_signe_selon_le_canal(): void
    {
        $this->patient->client->update(['user_id' => User::factory()->create()->id]);
        $devis = $this->devis();
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $entetes)->assertOk();

        $reponse = $this->postJson("/api/v1/facturation/devis/{$devis->id}/accepter", [], $entetes);

        $reponse->assertOk()->assertJsonPath('data.statut', 'accepte');
        // L'équipe enregistre l'accord : jamais présenté comme une signature électronique du client
        $this->assertStringStartsWith('Accord du propriétaire enregistré par ', $reponse->json('data.signe_par'));
        $this->assertStringNotContainsString('Signature électronique', $reponse->json('data.signe_par'));
        $this->assertNotNull($reponse->json('data.signe_le'));
    }

    public function test_un_devis_expire_est_derive_et_ne_s_accepte_plus(): void
    {
        $expire = $this->devis(['statut' => 'envoye', 'canal' => 'papier', 'valide_jusquau' => aujourdhui()->subDays(5)]);
        $entetes = $this->enTetes($this->jeton);

        $this->getJson("/api/v1/facturation/devis/{$expire->id}", $entetes)
            ->assertOk()
            ->assertJsonPath('data.statut', 'envoye')
            ->assertJsonPath('data.statut_effectif', 'expire');

        $this->postJson("/api/v1/facturation/devis/{$expire->id}/accepter", [], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['statut' => 'Ce devis est expiré : dupliquez-le pour repartir d’un brouillon.']);

        // Le filtre `expire` du registre le retrouve.
        $this->getJson('/api/v1/facturation/devis?statut=expire', $entetes)
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/facturation/devis?statut=envoye', $entetes)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_la_duplication_repart_en_brouillon_signature_remise_a_zero(): void
    {
        $accepte = $this->devis(['statut' => 'accepte', 'canal' => 'portail', 'signe_le' => now(), 'signe_par' => 'Signature électronique']);
        DevisLigne::factory()->create(['devis_id' => $accepte->id, 'libelle' => 'Chirurgie : forfait bloc', 'prix_chf' => 420]);

        $reponse = $this->postJson("/api/v1/facturation/devis/{$accepte->id}/dupliquer", [], $this->enTetes($this->jeton));

        $reponse->assertCreated()
            ->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.canal', null)
            ->assertJsonPath('data.signe_le', null)
            ->assertJsonPath('data.emis_le', aujourdhui()->toDateString())
            ->assertJsonPath('data.lignes.0.libelle', 'Chirurgie : forfait bloc');

        $this->assertNotSame($accepte->numero, $reponse->json('data.numero'));
    }

    public function test_seul_un_brouillon_se_supprime(): void
    {
        $brouillon = $this->devis();
        $envoye = $this->devis(['statut' => 'envoye', 'canal' => 'papier', 'valide_jusquau' => aujourdhui()->addDays(30)]);
        $entetes = $this->enTetes($this->jeton);

        $this->deleteJson("/api/v1/facturation/devis/{$brouillon->id}", [], $entetes)->assertNoContent();
        $this->deleteJson("/api/v1/facturation/devis/{$envoye->id}", [], $entetes)->assertUnprocessable()->assertJsonValidationErrors(['statut' => 'Seul un brouillon se supprime, un devis envoyé se refuse ou expire.']);
    }

    public function test_l_acompte_puis_la_finale_deduisent_remise_et_acompte(): void
    {
        $devis = $this->devis(['statut' => 'accepte', 'canal' => 'papier', 'signe_le' => now(), 'remise_pct' => 10, 'acompte_pct' => 30]);
        DevisLigne::factory()->create(['devis_id' => $devis->id, 'libelle' => 'Chirurgie : forfait bloc', 'prix_chf' => 400]);
        DevisLigne::factory()->create(['devis_id' => $devis->id, 'libelle' => 'Analyses laboratoire — profil standard', 'prix_chf' => 100]);
        $entetes = $this->enTetes($this->jeton);

        // Sous-total 500, remise 10 % → TTC 450, acompte 30 % → 135.
        $acompte = $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-acompte", [], $entetes);

        $acompte->assertCreated()
            ->assertJsonPath('data.total_ttc', 135)
            ->assertJsonPath('data.lignes.0.libelle', "Acompte 30 % : devis {$devis->numero}");

        $finale = $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-finale", [], $entetes);

        $finale->assertCreated()
            ->assertJsonCount(4, 'data.lignes')
            ->assertJsonPath('data.lignes.2.prix_chf', -50)
            ->assertJsonPath('data.lignes.3.prix_chf', -135)
            ->assertJsonPath('data.total_ttc', 315);

        $devis->refresh();
        $this->assertSame($acompte->json('data.id'), $devis->acompte_facture_id);
        $this->assertSame($finale->json('data.id'), $devis->facture_finale_id);

        // Chacune ne se facture qu'une fois.
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-acompte", [], $entetes)->assertUnprocessable()->assertJsonValidationErrors('acompte');
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-finale", [], $entetes)->assertUnprocessable()->assertJsonValidationErrors('facture');
    }

    /** Facture finale sans acompte d'abord : plus d'acompte ensuite, il serait facturé deux fois (31.10.2026) */
    public function test_pas_d_acompte_apres_la_facture_finale(): void
    {
        $devis = $this->devis(['statut' => 'accepte', 'canal' => 'papier', 'signe_le' => now(), 'acompte_pct' => 30]);
        DevisLigne::factory()->create(['devis_id' => $devis->id, 'libelle' => 'Chirurgie : forfait bloc', 'prix_chf' => 400]);
        $entetes = $this->enTetes($this->jeton);

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-finale", [], $entetes)->assertCreated();
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/facturer-acompte", [], $entetes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('acompte');
        $this->assertNull($devis->refresh()->acompte_facture_id);
    }

    public function test_seul_un_devis_accepte_se_facture(): void
    {
        $envoye = $this->devis(['statut' => 'envoye', 'canal' => 'papier', 'valide_jusquau' => aujourdhui()->addDays(30), 'acompte_pct' => 30]);

        $this->postJson("/api/v1/facturation/devis/{$envoye->id}/facturer-acompte", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statut');
    }
}
