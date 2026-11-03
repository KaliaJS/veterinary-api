<?php

namespace Tests\Feature\Documents;

use App\Enums\CanalDevis;
use App\Enums\StatutDemandeRendezVous;
use App\Enums\StatutDevis;
use App\Enums\StatutOrdonnance;
use App\Enums\StatutRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Document;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Ordonnance;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Services\Notifications\Push;
use App\Support\FreezeExistingHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Une pièce émise garde l'en-tête de son émission (étape 0 de la fusion des
 * dossiers, choix de Jim du 02.11.2026) : changer la fiche du client ou
 * l'adresse de la clinique ne change plus une facture, une ordonnance ou un
 * devis émis. Chaque test suit une case de notes/todo.md (dépôt web).
 */
class FrozenDocumentHeaderTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Client $client;

    private Patient $patient;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives', 'rue' => 'Rue du Simplon 24', 'npa' => '1006', 'ville' => 'Lausanne']);
        $this->clinique->societe->update(['nom' => 'Deux Rives Vétérinaires SA', 'iban' => 'CH9300762011623852957']);
        $organisation = $this->clinique->societe->organisation_id;
        $this->client = Client::factory()->create(['organisation_id' => $organisation, 'prenom' => 'Paul', 'nom' => 'Meunier', 'rue' => 'Rue des Pâquis 8', 'npa' => '1201', 'localite' => 'Genève']);
        $this->patient = Patient::factory()->for($this->client)->create(['organisation_id' => $organisation, 'nom' => 'Félix']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    /** Le client déménage, la clinique aussi */
    private function toutDemenage(): void
    {
        $this->client->update(['nom' => 'Meunier-Rochat', 'rue' => 'Avenue de France 3', 'npa' => '1950', 'localite' => 'Sion']);
        $this->clinique->update(['rue' => 'Place de la Gare 1', 'npa' => '1003', 'ville' => 'Lausanne']);
        $this->clinique->societe->update(['nom' => 'Deux Rives Groupe SA']);
    }

    private function facture(): Facture
    {
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'client_id' => $this->client->id, 'patient_id' => $this->patient->id, 'owner_name' => 'M. Meunier']);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 1, 'prix_chf' => 120]);

        return $facture;
    }

    public function test_une_facture_garde_le_nom_et_l_adresse_du_client_tels_qu_a_son_emission(): void
    {
        $facture = $this->facture();
        $this->toutDemenage();

        $data = $this->getJson("/api/v1/facturation/factures/{$facture->id}", $this->enTetes($this->jeton))->assertOk()->json('data');

        // La QR-facture : le débiteur de l'émission
        $this->assertSame(['nom' => 'Paul Meunier', 'rue' => 'Rue des Pâquis 8', 'npa' => '1201', 'localite' => 'Genève', 'pays' => 'CH'], $data['qr_facture']['debiteur']);
        // Le PDF : le destinataire et l'en-tête de la clinique de l'émission
        $this->assertSame('Rue des Pâquis 8', $data['recipient']['rue']);
        $this->assertSame('Paul Meunier', $data['recipient']['nom']);
        $this->assertSame('Rue du Simplon 24, 1006 Lausanne', $data['en_tete']['adresse']);
        $this->assertSame('Deux Rives Vétérinaires SA', $data['en_tete']['societe']);
        $this->assertArrayHasKey('logo_url', $data['en_tete']);

        // Dans l'espace du propriétaire aussi
        ['user' => $user, 'jeton' => $jetonEspace] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $user->id]);
        $pdf = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jetonEspace))->assertOk()->json('data'))->firstWhere('type', 'FACTURE')['pdf'];
        $this->assertSame('Rue du Simplon 24, 1006 Lausanne', $pdf['en_tete']['adresse']);
        $this->assertSame('Rue des Pâquis 8', $pdf['recipient']['rue']);
        $this->assertSame('Rue des Pâquis 8', $pdf['qr_facture']['debiteur']['rue']);
    }

    public function test_une_ordonnance_garde_le_nom_et_l_adresse_du_proprietaire_tels_qu_a_son_emission(): void
    {
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $nomAffiche = $this->client->nom_affiche;
        $this->toutDemenage();

        $data = $this->getJson("/api/v1/ordonnances/{$ordonnance->id}", $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertSame(['nom' => $nomAffiche, 'adresse' => 'Rue des Pâquis 8, 1201 Genève'], $data['document']['detenteur']);
        $this->assertSame('Rue du Simplon 24, 1006 Lausanne', $data['document']['en_tete']['adresse']);
        $this->assertSame('Rue du Simplon 24, 1006 Lausanne', $data['en_tete']['adresse']);
        $this->assertSame('Deux Rives Vétérinaires SA', $data['en_tete']['societe']);
    }

    public function test_un_devis_garde_le_nom_et_l_adresse_du_client_tels_qu_a_son_emission(): void
    {
        $devis = Devis::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => StatutDevis::Brouillon]);
        DevisLigne::factory()->create(['devis_id' => $devis->id]);

        // Brouillon : il suit encore la fiche (rien n'est émis)
        $this->client->update(['rue' => 'Rue de Lyon 2']);
        $this->assertSame('Rue de Lyon 2', $this->getJson("/api/v1/facturation/devis/{$devis->id}", $this->enTetes($this->jeton))->json('data.recipient.rue'));

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/envoyer", [], $this->enTetes($this->jeton))->assertOk();
        $this->toutDemenage();

        $data = $this->getJson("/api/v1/facturation/devis/{$devis->id}", $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame('Rue de Lyon 2', $data['recipient']['rue']);
        $this->assertSame('1201', $data['recipient']['npa']);
        $this->assertSame('Rue du Simplon 24, 1006 Lausanne', $data['en_tete']['adresse']);
        $this->assertSame('Deux Rives Vétérinaires SA', $data['en_tete']['societe']);
    }

    public function test_les_pieces_deja_emises_recoivent_une_fois_l_en_tete_de_la_fiche_actuelle_puis_ne_bougent_plus(): void
    {
        $facture = $this->facture();
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $devis = Devis::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => StatutDevis::Envoye]);
        $brouillon = Devis::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => StatutDevis::Brouillon]);
        // Des pièces d'avant la règle : sans en-tête figé
        foreach (['factures', 'ordonnances', 'devis'] as $table) {
            DB::table($table)->update(['recipient' => null, 'issuer' => null]);
        }
        $this->client->update(['rue' => 'Rue de Lyon 2']);

        app(FreezeExistingHeaders::class)->run();

        $this->assertSame('Rue de Lyon 2', $facture->refresh()->recipient['rue']);
        $this->assertSame('Rue de Lyon 2', $ordonnance->refresh()->recipient['rue']);
        $this->assertSame('Rue de Lyon 2', $devis->refresh()->recipient['rue']);
        $this->assertSame('Rue du Simplon 24, 1006 Lausanne', $facture->issuer['adresse']);
        // Un brouillon n'est pas émis : il reste sans en-tête figé
        $this->assertNull($brouillon->refresh()->recipient);

        // Puis plus rien ne bouge, même relancé
        $this->toutDemenage();
        app(FreezeExistingHeaders::class)->run();
        $this->assertSame('Rue de Lyon 2', $facture->refresh()->recipient['rue']);
        $this->assertSame('Rue du Simplon 24, 1006 Lausanne', $ordonnance->refresh()->issuer['adresse']);
        $this->assertSame('Deux Rives Vétérinaires SA', $devis->refresh()->issuer['societe']);
    }

    public function test_le_nouveau_proprietaire_ne_voit_aucune_piece_faite_pour_l_ancien(): void
    {
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $devis = Devis::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail]);
        // L'animal change de propriétaire : la nouvelle fiche a un compte
        ['user' => $user, 'jeton' => $jetonEspace] = $this->proprietaireAvecJeton();
        $nouvelle = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Rochat', 'rue' => 'Chemin Neuf 5', 'npa' => '1009', 'localite' => 'Pully', 'user_id' => $user->id]);
        $this->patient->update(['client_id' => $nouvelle->id]);

        $documents = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jetonEspace))->assertOk()->json('data'));

        // Faites pour M. Meunier : ni l'ordonnance ni le devis ne sont montrés au nouveau propriétaire,
        // et rien de M. Meunier n'apparaît (décision de Jim, 02.11.2026)
        $this->assertNull($documents->firstWhere('type', 'ORDONNANCE'));
        $this->assertNull($documents->firstWhere('type', 'DEVIS'));
        $this->assertStringNotContainsString('Pâquis', $documents->toJson(JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Meunier', $documents->toJson(JSON_UNESCAPED_UNICODE));

        // Ni signer le devis, ni demander le renouvellement de l'ordonnance de l'ancien
        $ordonnance->update(['renouvellements' => 2]);
        $this->postJson("/api/v1/espace/devis/{$devis->id}/signer", ['accepte' => true], $this->enTetes($jetonEspace))->assertNotFound();
        $this->postJson("/api/v1/espace/ordonnances/{$ordonnance->id}/renouvellement", [], $this->enTetes($jetonEspace))->assertNotFound();

        // Une pièce faite pour lui, après le changement : il la voit, avec son nom
        $neuve = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        $documents = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jetonEspace))->assertOk()->json('data'));
        $this->assertSame($neuve->id, $documents->firstWhere('type', 'ORDONNANCE')['id']);
        $this->assertSame('Chemin Neuf 5, 1009 Pully', $documents->firstWhere('type', 'ORDONNANCE')['pdf']['detenteur']['adresse']);
        $this->assertNotSame($ordonnance->id, $neuve->id);
        $this->assertNotNull($devis->id);
    }

    public function test_ni_l_accueil_ni_la_fiche_ni_les_rendez_vous_ni_les_documents_ne_montrent_ce_qui_etait_a_l_ancien(): void
    {
        $organisation = $this->clinique->societe->organisation_id;
        Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => StatutOrdonnance::Active, 'renouvellements' => 2]);
        $devis = Devis::factory()->create(['organisation_id' => $organisation, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail, 'envoye_le' => now()->subDay(), 'valide_jusquau' => now()->addDays(20)]);
        DevisLigne::factory()->create(['devis_id' => $devis->id]);
        $rdv = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->subMonth(), 'ends_at' => now()->subMonth()->addMinutes(20), 'status' => StatutRendezVous::Fini]);
        // Le consentement signé par l'ancien, par le chemin réel (ConsentRecorder)
        $consentement = Document::factory()->create(['patient_id' => $this->patient->id, 'type' => 'consentement', 'signataire' => 'Paul Meunier', 'client_id' => $this->client->id]);

        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $nouvelle = Client::factory()->create(['organisation_id' => $organisation, 'nom' => 'Rochat', 'user_id' => $user->id]);
        $this->patient->update(['client_id' => $nouvelle->id]);

        $accueil = $this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))->assertOk()->json('data');
        $this->assertNull($accueil['ordonnance']);
        $this->assertEmpty(collect($accueil['a_faire'])->where('type', 'devis'));

        $this->assertNull($this->getJson("/api/v1/espace/animaux/{$this->patient->id}", $this->enTetes($jeton))->assertOk()->json('data.ordonnance_active_id'));

        $rdvs = $this->getJson('/api/v1/espace/rendez-vous', $this->enTetes($jeton))->assertOk()->json('data');
        $this->assertNotContains($rdv->id, collect($rdvs['passes'])->pluck('id'));

        $documents = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jeton))->assertOk()->json('data'));
        $this->assertNotContains($consentement->id, $documents->where('type', 'CONSENTEMENT')->pluck('id'));
        $this->assertStringNotContainsString('Meunier', $documents->toJson(JSON_UNESCAPED_UNICODE));
    }

    public function test_ni_les_demandes_ni_le_prochain_rendez_vous_de_l_ancien_ne_vont_au_nouveau(): void
    {
        $organisation = $this->clinique->societe->organisation_id;
        $rdv = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addMinutes(20)]);
        $demande = DemandeRendezVous::factory()->create([
            'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'client_id' => $this->client->id,
            'reason' => 'SECRET-ANCIEN', 'rendez_vous_id' => $rdv->id, 'statut' => StatutDemandeRendezVous::Acceptee,
        ]);

        ['user' => $user, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $nouvelle = Client::factory()->create(['organisation_id' => $organisation, 'nom' => 'Rochat', 'user_id' => $user->id]);
        $this->patient->update(['client_id' => $nouvelle->id]);

        $demandes = $this->getJson('/api/v1/espace/demandes', $this->enTetes($jeton))->assertOk();
        $this->assertStringNotContainsString('SECRET-ANCIEN', $demandes->getContent());
        $rdvs = $this->getJson('/api/v1/espace/rendez-vous', $this->enTetes($jeton))->assertOk()->json('data');
        $this->assertNotContains($rdv->id, collect($rdvs['a_venir'])->pluck('id'));
        $this->assertNull($this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))->assertOk()->json('data.prochain_rendez_vous'));
        $this->postJson("/api/v1/espace/rendez-vous/{$rdv->id}/annuler", [], $this->enTetes($jeton))->assertNotFound();
        $this->assertNotNull($demande->id);
    }

    public function test_les_notifications_d_un_rendez_vous_vont_a_celui_qui_l_a_pris(): void
    {
        ['user' => $ancien] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $ancien->id]);
        $rdv = RendezVous::factory()->pourPatient($this->patient)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addMinutes(20)]);
        ['user' => $nouveau] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Rochat', 'user_id' => $nouveau->id]);
        $this->patient->update(['client_id' => $fiche->id]);

        $this->assertSame([$ancien->id], Push::ownersOf($rdv->refresh()));
    }

    public function test_un_animal_reclame_par_sa_puce_quitte_l_espace_du_compte_quand_il_change_de_proprietaire(): void
    {
        ['user' => $lea, 'jeton' => $jetonLea] = $this->proprietaireAvecJeton();
        $this->patient->forceFill(['proprietaire_id' => $lea->id])->save();
        $sansCompte = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Rochat']);

        $this->putJson("/api/v1/patients/{$this->patient->id}", ['client_id' => $sansCompte->id], $this->enTetes($this->jeton))->assertOk();

        $this->assertNull($this->patient->refresh()->proprietaire_id);
        $this->getJson("/api/v1/espace/animaux/{$this->patient->id}", $this->enTetes($jetonLea))->assertNotFound();
    }

    public function test_une_meme_personne_dont_l_animal_change_de_fiche_garde_ses_pieces(): void
    {
        ['user' => $lea, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $lea->id]);
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id]);
        // Sa seconde fiche (doublon) dans la même organisation
        $autreFiche = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Meunier', 'user_id' => $lea->id]);
        $this->patient->update(['client_id' => $autreFiche->id]);

        $documents = collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jeton))->assertOk()->json('data'));
        $this->assertSame($ordonnance->id, $documents->firstWhere('type', 'ORDONNANCE')['id']);
    }

    public function test_une_demande_deposee_par_l_ancien_compte_ne_va_pas_au_nouveau(): void
    {
        ['user' => $ancien] = $this->proprietaireAvecJeton();
        // Déposée par son compte, sans fiche (une autre clinique où il n'en avait pas)
        DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'client_id' => null, 'user_id' => $ancien->id, 'reason' => 'SECRET-COMPTE']);
        ['user' => $nouveau, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $nouveau->id]);

        $this->assertStringNotContainsString('SECRET-COMPTE', $this->getJson('/api/v1/espace/demandes', $this->enTetes($jeton))->assertOk()->getContent());
    }

    public function test_la_clinique_ne_relance_pas_a_la_main_le_devis_de_l_ancien_chez_le_nouveau(): void
    {
        Mail::fake();
        $devis = Devis::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail, 'envoye_le' => now()->subDays(3), 'valide_jusquau' => now()->addDays(20)]);
        ['user' => $nouveau] = $this->proprietaireAvecJeton();
        $fiche = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'nom' => 'Rochat', 'user_id' => $nouveau->id]);
        $this->patient->update(['client_id' => $fiche->id]);

        $this->postJson("/api/v1/facturation/devis/{$devis->id}/relancer", [], $this->enTetes($this->jeton))
            ->assertUnprocessable()->assertJsonValidationErrors('relance');
        Mail::assertNothingSent();
    }

    public function test_une_meme_personne_avec_deux_fiches_garde_tout_partout(): void
    {
        Mail::fake();
        ['user' => $lea, 'jeton' => $jeton] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $lea->id, 'email' => 'lea@example.ch']);
        $organisation = $this->clinique->societe->organisation_id;
        $ordonnance = Ordonnance::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => StatutOrdonnance::Active, 'renouvellements' => 2]);
        $devis = Devis::factory()->create(['organisation_id' => $organisation, 'clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'statut' => StatutDevis::Envoye, 'canal' => CanalDevis::Portail, 'envoye_le' => now()->subDay(), 'valide_jusquau' => now()->addDays(20)]);
        DevisLigne::factory()->create(['devis_id' => $devis->id]);
        $autreFiche = Client::factory()->create(['organisation_id' => $organisation, 'nom' => 'Meunier', 'user_id' => $lea->id]);
        $this->patient->update(['client_id' => $autreFiche->id]);

        $accueil = $this->getJson('/api/v1/espace/accueil', $this->enTetes($jeton))->assertOk()->json('data');
        $this->assertSame($ordonnance->id, $accueil['ordonnance']['id']);
        $this->assertNotEmpty(collect($accueil['a_faire'])->where('type', 'devis'));
        $this->assertSame($ordonnance->id, $this->getJson("/api/v1/espace/animaux/{$this->patient->id}", $this->enTetes($jeton))->json('data.ordonnance_active_id'));
        $this->assertSame($devis->id, collect($this->getJson('/api/v1/espace/documents', $this->enTetes($jeton))->json('data'))->firstWhere('type', 'DEVIS')['id']);
        $this->postJson("/api/v1/espace/ordonnances/{$ordonnance->id}/renouvellement", [], $this->enTetes($jeton))->assertCreated();
        // La clinique peut toujours relancer son devis
        $this->postJson("/api/v1/facturation/devis/{$devis->id}/relancer", [], $this->enTetes($this->jeton))->assertOk();
        $this->postJson("/api/v1/espace/devis/{$devis->id}/signer", ['accepte' => true], $this->enTetes($jeton))->assertOk();
    }

    public function test_enregistrer_la_fiche_sans_changer_de_proprietaire_garde_le_compte_de_la_puce(): void
    {
        ['user' => $lea] = $this->proprietaireAvecJeton();
        $this->patient->forceFill(['proprietaire_id' => $lea->id])->save();

        // La webapp renvoie toujours client_id avec la fiche
        $this->putJson("/api/v1/patients/{$this->patient->id}", ['client_id' => $this->patient->client_id, 'race' => 'Berger australien'], $this->enTetes($this->jeton))->assertOk();

        $this->assertSame($lea->id, $this->patient->refresh()->proprietaire_id);
    }
}
