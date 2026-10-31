<?php

namespace Tests\Feature\Espace;

use App\Enums\CanalDevis;
use App\Enums\StatutDevis;
use App\Events\DevisMisAJour;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Patient;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le propriétaire répond à un devis dans son espace (31.10.2026, fenêtre
 * choisie par Jim) : il l'accepte après avoir coché qu'il l'a lu, la
 * signature porte le nom de sa fiche ; ou il le refuse, avec un mot s'il
 * le veut. Dans les deux cas la clinique le voit tout de suite (Reverb).
 */
class ReponseDevisProprietaireTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    /** @return array{jeton: string, devis: Devis} */
    private function devisEnvoye(): array
    {
        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton(['name' => 'lea.martin']);
        $fiche = Client::factory()->create(['user_id' => $user->id, 'prenom' => 'Léa', 'nom' => 'Martin']);
        $patient = Patient::factory()->create(['client_id' => $fiche->id]);
        $devis = Devis::factory()->create(['patient_id' => $patient->id, 'organisation_id' => $fiche->organisation_id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail, 'signe_le' => null]);

        return ['jeton' => $jeton, 'devis' => $devis];
    }

    public function test_accepter_demande_d_avoir_lu_le_devis_et_signe_au_nom_de_la_fiche(): void
    {
        Event::fake([DevisMisAJour::class]);
        ['jeton' => $jeton, 'devis' => $devis] = $this->devisEnvoye();

        $this->postJson("/api/v1/espace/devis/{$devis->id}/signer", [], $this->enTetes($jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accepte' => 'Cochez « J’ai lu le devis et j’accepte les soins prévus et ses conditions ».']);
        $this->assertSame(StatutDevis::Envoye, $devis->refresh()->statut);
        Event::assertNotDispatched(DevisMisAJour::class);

        $this->postJson("/api/v1/espace/devis/{$devis->id}/signer", ['accepte' => true], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'accepte');
        $devis->refresh();
        $this->assertSame('Signature électronique de Léa Martin, espace client', $devis->signe_par);
        Event::assertDispatched(DevisMisAJour::class, fn (DevisMisAJour $e) => $e->devis->is($devis) && $e->broadcastWith()['statut'] === 'accepte');
    }

    public function test_refuser_avec_un_mot_pour_la_clinique(): void
    {
        Event::fake([DevisMisAJour::class]);
        ['jeton' => $jeton, 'devis' => $devis] = $this->devisEnvoye();

        $this->postJson("/api/v1/espace/devis/{$devis->id}/refuser", ['motif' => '  Trop cher pour le moment.  '], $this->enTetes($jeton))
            ->assertOk()
            ->assertJsonPath('data.statut', 'refuse');
        $devis->refresh();
        $this->assertSame(StatutDevis::Refuse, $devis->statut);
        $this->assertSame('Refusé par Léa Martin dans son espace client', $devis->refuse_par);
        $this->assertSame('Trop cher pour le moment.', $devis->motif_refus);
        $this->assertNotNull($devis->refuse_le);
        Event::assertDispatched(DevisMisAJour::class, fn (DevisMisAJour $e) => $e->broadcastOn()[0]->name === 'private-clinique.'.$devis->clinique_id);

        // Déjà refusé : ni une seconde réponse, ni une signature
        $this->postJson("/api/v1/espace/devis/{$devis->id}/refuser", [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('statut');
        $this->postJson("/api/v1/espace/devis/{$devis->id}/signer", ['accepte' => true], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('statut');
    }

    public function test_le_devis_d_un_autre_ou_un_devis_papier_ne_se_refuse_pas(): void
    {
        ['jeton' => $jeton, 'devis' => $devis] = $this->devisEnvoye();
        $papier = Devis::factory()->create(['patient_id' => $devis->patient_id, 'organisation_id' => $devis->organisation_id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Papier]);
        $autre = Devis::factory()->create(['statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail]);

        $this->postJson("/api/v1/espace/devis/{$papier->id}/refuser", [], $this->enTetes($jeton))->assertNotFound();
        $this->postJson("/api/v1/espace/devis/{$autre->id}/refuser", [], $this->enTetes($jeton))->assertNotFound();
        $this->postJson("/api/v1/espace/devis/{$devis->id}/refuser", ['motif' => str_repeat('a', 501)], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('motif');
    }

    public function test_la_liste_des_documents_porte_ce_que_montre_le_devis(): void
    {
        ['jeton' => $jeton, 'devis' => $devis] = $this->devisEnvoye();
        $devis->patient->client->update(['rue' => 'Rue des Pâquis 8', 'npa' => '1201', 'localite' => 'Genève']);
        $devis->lignes()->create(['libelle' => 'Détartrage', 'quantite' => 2, 'prix_chf' => 90, 'type' => 'acte']);

        $item = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jeton))->assertOk()->json('data'))->firstWhere('type', 'DEVIS');
        $this->assertSame(['nom' => $devis->patient->client->fresh()->nom_affiche, 'adresse' => 'Rue des Pâquis 8, 1201 Genève'], $item['pdf']['proprietaire']);
        $this->assertSame('acte', $item['pdf']['lignes'][0]['type']);
        $this->assertEquals(90, $item['pdf']['lignes'][0]['prix_chf']);
        $this->assertSame($devis->patient->espece->value, $item['pdf']['espece']);
    }

    public function test_un_devis_expire_ne_se_signe_ni_ne_se_refuse(): void
    {
        ['jeton' => $jeton, 'devis' => $devis] = $this->devisEnvoye();
        $devis->update(['valide_jusquau' => aujourdhui()->subDay()]);

        $this->postJson("/api/v1/espace/devis/{$devis->id}/refuser", [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors(['statut' => 'Ce devis est expiré : demandez-en un nouveau à la clinique.']);
        $this->postJson("/api/v1/espace/devis/{$devis->id}/signer", ['accepte' => true], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('statut');
    }

    /** L'équipe a répondu pendant que le client lisait : sa réponse n'écrase pas celle de l'équipe. */
    public function test_la_reponse_du_client_n_ecrase_pas_celle_de_l_equipe(): void
    {
        ['jeton' => $jeton, 'devis' => $devis] = $this->devisEnvoye();
        // Le devis est lu « envoyé », puis l'équipe l'accepte juste avant l'écriture du client
        $fait = false;
        DB::listen(function ($requete) use (&$fait, $devis): void {
            if (! $fait && str_starts_with(strtolower($requete->sql), 'select') && preg_match('/from [`"]devis[`"]/', $requete->sql) === 1) {
                $fait = true;
                DB::table('devis')->where('id', $devis->id)->update(['statut' => StatutDevis::Accepte->value, 'signe_le' => now(), 'signe_par' => 'Accord enregistré par Dr Meyer']);
            }
        });

        $this->postJson("/api/v1/espace/devis/{$devis->id}/refuser", [], $this->enTetes($jeton))->assertUnprocessable()->assertJsonValidationErrors('statut');
        $this->assertSame(StatutDevis::Accepte, $devis->refresh()->statut);
        $this->assertNull($devis->refuse_le);
    }

    public function test_reverb_en_panne_la_reponse_du_client_reste_enregistree(): void
    {
        ['jeton' => $jeton, 'devis' => $devis] = $this->devisEnvoye();
        $this->mock(Factory::class, function ($mock): void {
            $mock->shouldReceive('event')->andThrow(new \RuntimeException('Reverb injoignable'));
            $mock->shouldReceive('connection')->andThrow(new \RuntimeException('Reverb injoignable'));
        });

        $this->postJson("/api/v1/espace/devis/{$devis->id}/signer", ['accepte' => true], $this->enTetes($jeton))->assertOk();
        $this->assertSame(StatutDevis::Accepte, $devis->refresh()->statut);
    }

    public function test_la_liste_sert_le_statut_et_cache_la_fiche_d_un_autre(): void
    {
        ['jeton' => $jeton, 'devis' => $devis] = $this->devisEnvoye();
        $devis->update(['statut' => StatutDevis::Refuse]);
        $item = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jeton))->assertOk()->json('data'))->firstWhere('type', 'DEVIS');
        $this->assertSame('refuse', $item['pdf']['statut']);
        $this->assertNotNull($item['pdf']['proprietaire']);

        // Qui gère un animal réclamé par puce ne voit pas la fiche (nom, adresse) d'un autre
        ['user' => $user, 'jeton' => $autreJeton] = $this->proprietaireAvecJeton();
        $ficheDUnAutre = Client::factory()->create();
        $rex = Patient::factory()->create(['client_id' => $ficheDUnAutre->id, 'proprietaire_id' => $user->id]);
        Devis::factory()->create(['patient_id' => $rex->id, 'organisation_id' => $ficheDUnAutre->organisation_id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail]);
        $item = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($autreJeton))->assertOk()->json('data'))->firstWhere('type', 'DEVIS');
        $this->assertNull($item['pdf']['proprietaire']);
        $this->assertFalse($item['a_signer']);
    }
}
