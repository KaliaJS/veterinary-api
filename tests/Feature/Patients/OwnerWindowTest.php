<?php

namespace Tests\Feature\Patients;

use App\Enums\StatutDemandeRendezVous;
use App\Enums\StatutRendezVous;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\DemandeRendezVous;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\MessageClientele;
use App\Models\Patient;
use App\Models\PreferencesProprietaire;
use App\Models\RappelEnvoye;
use App\Models\RendezVous;
use App\Models\User;
use App\Models\Vaccination;
use App\Models\Visite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * La fenêtre propriétaire, proposition A (choix de Jim, 03.11.2026) :
 * l'historique sur un fil, l'état de chaque animal, les coordonnées
 * (contact d'urgence), les rappels et préférences (le compte décide quand
 * il existe), la note de l'accueil. Chaque test suit une case de
 * notes/todo.md (dépôt web).
 */
class OwnerWindowTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Client $client;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(10, 0));
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        $this->client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'prenom' => 'Baby', 'nom' => 'Adam', 'tel' => '078 146 54 09', 'email' => 'block.famille@gmail.com']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    private function animal(array $attributs = []): Patient
    {
        return Patient::factory()->for($this->client)->create(['organisation_id' => $this->client->organisation_id, ...$attributs]);
    }

    public function test_l_historique_raconte_la_relation_sur_un_fil_avec_l_animal_et_encaisser(): void
    {
        $cannelle = $this->animal(['nom' => 'Cannelle']);
        $ramen = $this->animal(['nom' => 'Ramen']);
        RendezVous::factory()->pourPatient($ramen)->create(['clinique_id' => $this->clinique->id, 'reason' => 'Rappel CHPPi', 'starts_at' => now()->addDays(9), 'ends_at' => now()->addDays(9)->addMinutes(20)]);
        RendezVous::factory()->pourPatient($cannelle)->create(['clinique_id' => $this->clinique->id, 'reason' => 'Boiterie', 'status' => StatutRendezVous::Fini, 'starts_at' => now()->subDays(7), 'ends_at' => now()->subDays(7)->addMinutes(20)]);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'client_id' => $this->client->id, 'patient_id' => $cannelle->id, 'patient_name' => 'Cannelle', 'emise_le' => now()->subDays(7)]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 1, 'prix_chf' => 92]);
        RappelEnvoye::withoutGlobalScopes()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $ramen->id, 'client_id' => $this->client->id, 'objet' => 'vaccin', 'libelle' => 'CHPPi', 'echeance_le' => now()->addDays(20), 'canal' => 'sms', 'statut' => 'envoye', 'envoye_le' => now()->subDays(2)]);
        DemandeRendezVous::factory()->create(['clinique_id' => $this->clinique->id, 'client_id' => $this->client->id, 'patient_id' => $cannelle->id, 'reason' => 'Cannelle boite depuis deux jours', 'statut' => StatutDemandeRendezVous::Acceptee, 'created_at' => now()->subDays(11)]);
        $lea = User::factory()->create();
        $this->client->update(['user_id' => $lea->id]);
        MessageClientele::query()->create(['clinique_id' => $this->clinique->id, 'user_id' => $lea->id, 'client_id' => $this->client->id, 'auteur' => 'proprietaire', 'texte' => 'Cannelle mange de nouveau bien, merci !']);

        $data = $this->getJson("/api/v1/clients/{$this->client->id}/historique", $this->enTetes($this->jeton))->assertOk()->json('data');

        $types = collect($data)->pluck('type')->all();
        foreach (['rdv', 'facture', 'rappel', 'demande', 'message'] as $type) {
            $this->assertContains($type, $types, $type);
        }
        // Ce qui vient en tête, puis du plus récent au plus ancien
        $this->assertTrue($data[0]['a_venir']);
        $this->assertSame('Ramen', $data[0]['animal']);
        $dates = collect($data)->where('a_venir', false)->pluck('date')->all();
        $this->assertSame($dates, collect($dates)->sortDesc()->values()->all());
        // La facture due porte de quoi l'encaisser
        $ligne = collect($data)->firstWhere('type', 'facture');
        $this->assertSame($facture->id, $ligne['facture_id']);
        $this->assertEquals(92, $ligne['reste_a_payer']);
        $this->assertSame('Cannelle', $ligne['animal']);
    }

    /** Le web écarte une lecture plus ancienne qu'un enregistrement reçu : la version le dit */
    public function test_chaque_enregistrement_augmente_la_version_servie(): void
    {
        $avant = $this->getJson("/api/v1/clients/{$this->client->id}", $this->enTetes($this->jeton))->assertOk()->json('data.version');
        $this->assertIsInt($avant);

        $apres = $this->putJson("/api/v1/clients/{$this->client->id}", ['note' => 'Première'], $this->enTetes($this->jeton))->assertOk()->json('data.version');
        $encore = $this->putJson("/api/v1/clients/{$this->client->id}", ['note' => 'Seconde'], $this->enTetes($this->jeton))->assertOk()->json('data.version');

        $this->assertSame($avant + 1, $apres);
        $this->assertSame($apres + 1, $encore);
    }

    public function test_l_historique_se_pagine(): void
    {
        $felix = $this->animal(['nom' => 'Félix']);
        foreach (range(1, 45) as $i) {
            RendezVous::factory()->pourPatient($felix)->create(['clinique_id' => $this->clinique->id, 'status' => StatutRendezVous::Fini, 'starts_at' => now()->subDays($i), 'ends_at' => now()->subDays($i)->addMinutes(20)]);
        }

        $page = $this->getJson("/api/v1/clients/{$this->client->id}/historique", $this->enTetes($this->jeton))->assertOk();
        $this->assertCount(30, $page->json('data'));
        $suivant = $page->json('meta.suivant');
        $this->assertNotNull($suivant);

        $page2 = $this->getJson("/api/v1/clients/{$this->client->id}/historique?avant=".urlencode($suivant), $this->enTetes($this->jeton))->assertOk();
        $this->assertCount(15, $page2->json('data'));
        $this->assertNull($page2->json('meta.suivant'));
    }

    public function test_l_historique_ne_perd_rien_a_la_limite_d_une_page(): void
    {
        $felix = $this->animal(['nom' => 'Félix']);
        foreach (range(1, 29) as $i) {
            RendezVous::factory()->pourPatient($felix)->create(['clinique_id' => $this->clinique->id, 'status' => StatutRendezVous::Fini, 'starts_at' => now()->subDays($i), 'ends_at' => now()->subDays($i)->addMinutes(20)]);
        }
        // Trois rappels partis à la même seconde (un envoi groupé), à cheval sur la page
        foreach (['CHPPi', 'Rage', 'Leptospirose'] as $libelle) {
            RappelEnvoye::withoutGlobalScopes()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $felix->id, 'client_id' => $this->client->id, 'objet' => 'vaccin', 'libelle' => $libelle, 'echeance_le' => now(), 'canal' => 'email', 'statut' => 'envoye', 'envoye_le' => now()->subDays(40)]);
        }

        $vus = $this->toutLeFil();
        $this->assertCount(32, $vus->unique());
        $this->assertCount(32, $vus);
    }

    public function test_l_historique_lit_tout_un_envoi_groupe_plus_long_qu_une_page(): void
    {
        $felix = $this->animal(['nom' => 'Félix']);
        // 35 rappels partis à la même seconde, plus que les 30 d'une page
        foreach (range(1, 35) as $i) {
            RappelEnvoye::withoutGlobalScopes()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $felix->id, 'client_id' => $this->client->id, 'objet' => 'vaccin', 'libelle' => "Rappel {$i}", 'echeance_le' => now(), 'canal' => 'email', 'statut' => 'envoye', 'envoye_le' => now()->subDays(2)]);
        }
        RendezVous::factory()->pourPatient($felix)->create(['clinique_id' => $this->clinique->id, 'status' => StatutRendezVous::Fini, 'starts_at' => now()->subDays(5), 'ends_at' => now()->subDays(5)->addMinutes(20)]);

        $vus = $this->toutLeFil();
        $this->assertCount(36, $vus);
        $this->assertCount(36, $vus->unique());
    }

    /** Tout le fil, page après page, en suivant `meta.suivant` et `meta.sauter`. */
    private function toutLeFil(): Collection
    {
        $vus = collect();
        $curseur = '';
        do {
            $page = $this->getJson("/api/v1/clients/{$this->client->id}/historique".$curseur, $this->enTetes($this->jeton))->assertOk();
            $vus = $vus->merge(collect($page->json('data'))->map(fn ($x) => $x['type'].':'.$x['id']));
            $suivant = $page->json('meta.suivant');
            $curseur = $suivant ? '?'.http_build_query(['avant' => $suivant, 'sauter' => $page->json('meta.sauter')]) : '';
        } while ($suivant !== null && $vus->count() < 200);

        return $vus;
    }

    public function test_l_historique_se_filtre_par_type(): void
    {
        $cannelle = $this->animal(['nom' => 'Cannelle']);
        RendezVous::factory()->pourPatient($cannelle)->create(['clinique_id' => $this->clinique->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(20)]);
        RendezVous::factory()->pourPatient($cannelle)->create(['clinique_id' => $this->clinique->id, 'status' => StatutRendezVous::Fini, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addMinutes(20)]);
        $facture = Facture::factory()->create(['clinique_id' => $this->clinique->id, 'client_id' => $this->client->id, 'patient_id' => $cannelle->id, 'patient_name' => 'Cannelle', 'emise_le' => now()->subDays(3)]);
        FactureLigne::factory()->create(['facture_id' => $facture->id, 'quantite' => 1, 'prix_chf' => 40]);

        $factures = $this->getJson("/api/v1/clients/{$this->client->id}/historique?type=facture", $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertSame(['facture'], collect($factures)->pluck('type')->unique()->values()->all());
        $rdv = $this->getJson("/api/v1/clients/{$this->client->id}/historique?type=rdv", $this->enTetes($this->jeton))->assertOk()->json('data');
        $this->assertCount(2, $rdv);
        $this->assertSame(['rdv'], collect($rdv)->pluck('type')->unique()->values()->all());
        $this->getJson("/api/v1/clients/{$this->client->id}/historique?type=lune", $this->enTetes($this->jeton))->assertStatus(422);
    }

    /**
     * Audit C02 (13.11.2026) : l'espace dépose, dans la clinique d'une autre
     * organisation, une demande qui garde l'animal suivi ici mais sans fiche
     * client (aucune fiche de cette organisation-là). Son motif ne remonte
     * jamais dans l'historique de cette organisation-ci.
     */
    public function test_l_historique_ne_montre_pas_une_demande_deposee_dans_une_autre_organisation(): void
    {
        $rex = $this->animal(['nom' => 'Rex']);
        $concurrente = Clinique::factory()->create();
        DemandeRendezVous::factory()->create(['clinique_id' => $concurrente->id, 'client_id' => null, 'patient_id' => $rex->id, 'reason' => 'Deuxième avis pour la boiterie']);

        $data = $this->getJson("/api/v1/clients/{$this->client->id}/historique", $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertSame([], collect($data)->where('type', 'demande')->values()->all());
    }

    /** Les cliniques sœurs partagent le dossier : leurs demandes restent dans l'historique. */
    public function test_l_historique_montre_une_demande_deposee_dans_une_clinique_soeur(): void
    {
        $rex = $this->animal(['nom' => 'Rex']);
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        DemandeRendezVous::factory()->create(['clinique_id' => $soeur->id, 'client_id' => null, 'patient_id' => $rex->id, 'reason' => 'Vaccin annuel']);

        $data = $this->getJson("/api/v1/clients/{$this->client->id}/historique", $this->enTetes($this->jeton))->assertOk()->json('data');

        $this->assertSame(['Demande en ligne : « Vaccin annuel »'], collect($data)->where('type', 'demande')->pluck('titre')->values()->all());
    }

    public function test_chaque_animal_a_son_etat(): void
    {
        $traite = $this->animal(['nom' => 'Cannelle']);
        $traite->forceFill(['traitement_en_cours' => 'Méloxicam 0,1 mg/kg, 14 jours'])->save();
        $retard = $this->animal(['nom' => 'Ramen', 'traitement_en_cours' => null]);
        Vaccination::factory()->create(['patient_id' => $retard->id, 'nom' => 'CHPPi', 'injectee_le' => now()->subYears(1)->subMonth(), 'echeance_le' => now()->subDays(22)]);
        Visite::factory()->create(['patient_id' => $retard->id, 'visite_le' => now()->subYear()]);
        $neuf = $this->animal(['nom' => 'Kiwi', 'traitement_en_cours' => null]);
        $ajour = $this->animal(['nom' => 'Rex', 'traitement_en_cours' => null]);
        Vaccination::factory()->create(['patient_id' => $ajour->id, 'nom' => 'Rage', 'injectee_le' => now()->subMonths(2), 'echeance_le' => now()->addMonths(10)]);
        Visite::factory()->create(['patient_id' => $ajour->id, 'visite_le' => now()->subMonths(2)]);

        $etats = collect($this->getJson("/api/v1/clients/{$this->client->id}", $this->enTetes($this->jeton))->assertOk()->json('data.etats_animaux'))->keyBy('patient_id');

        $this->assertSame('traitement', $etats[$traite->id]['code']);
        $this->assertStringContainsString('Méloxicam', $etats[$traite->id]['detail']);
        $this->assertSame('retard', $etats[$retard->id]['code']);
        $this->assertStringContainsString('CHPPi', $etats[$retard->id]['detail']);
        $this->assertSame('jamais_vu', $etats[$neuf->id]['code']);
        $this->assertSame('a_jour', $etats[$ajour->id]['code']);
    }

    public function test_coordonnees_contact_d_urgence_et_note_de_l_accueil(): void
    {
        $this->putJson("/api/v1/clients/{$this->client->id}", [
            'emergency_contact' => 'M. Daniel Adam, son mari', 'emergency_phone' => '079 220 31 77',
            'note' => 'Préfère être appelée après 17 h.',
        ], $this->enTetes($this->jeton))->assertOk()
            ->assertJsonPath('data.emergency_contact', 'M. Daniel Adam, son mari')
            ->assertJsonPath('data.emergency_phone', '079 220 31 77')
            ->assertJsonPath('data.note', 'Préfère être appelée après 17 h.');
    }

    public function test_la_note_de_l_accueil_ne_part_jamais_dans_l_espace_du_proprietaire(): void
    {
        ['user' => $lea, 'jeton' => $jetonEspace] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $lea->id, 'note' => 'NOTE-INTERNE']);

        foreach (['/api/v1/espace/accueil', '/api/v1/espace/animaux', '/api/v1/espace/coordonnees', '/api/v1/espace/preferences'] as $url) {
            $reponse = $this->getJson($url, $this->enTetes($jetonEspace));
            $this->assertStringNotContainsString('NOTE-INTERNE', $reponse->getContent(), $url);
        }
    }

    public function test_sans_compte_la_clinique_regle_les_rappels_et_preferences_sur_la_fiche(): void
    {
        $data = $this->putJson("/api/v1/clients/{$this->client->id}", [
            'rappels_sms' => true, 'rappels_email' => false, 'muted_reminders' => ['quotes'], 'language' => 'de', 'accepts_news' => true, 'photo_consent' => true,
        ], $this->enTetes($this->jeton))->assertOk()->json('data.preferences');

        $this->assertSame('fiche', $data['source']);
        $this->assertNull($data['espace_depuis']);
        $this->assertTrue($data['est_modifiable']);
        $this->assertTrue($data['sms']);
        $this->assertFalse($data['email']);
        $this->assertSame(['quotes'], $data['muted_reminders']);
        $this->assertSame('de', $data['language']);
        $this->assertTrue($data['accepts_news']);
        $this->assertNotNull($data['photo_consent_at']);
    }

    public function test_avec_un_compte_ses_choix_font_foi_et_la_clinique_ne_les_modifie_pas(): void
    {
        $lea = User::factory()->create();
        $this->client->update(['user_id' => $lea->id]);
        PreferencesProprietaire::pour($lea)->update(['rappels_email' => false, 'langue' => 'it', 'nouveautes_boutique' => true, 'muted_reminders' => ['checkups']]);

        $data = $this->getJson("/api/v1/clients/{$this->client->id}", $this->enTetes($this->jeton))->assertOk()->json('data.preferences');
        $this->assertSame('compte', $data['source']);
        $this->assertNotNull($data['espace_depuis']);
        $this->assertFalse($data['est_modifiable']);
        $this->assertFalse($data['email']);
        $this->assertSame('it', $data['language']);
        $this->assertTrue($data['accepts_news']);
        $this->assertSame(['checkups'], $data['muted_reminders']);

        $this->putJson("/api/v1/clients/{$this->client->id}", ['muted_reminders' => ['vaccines']], $this->enTetes($this->jeton))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['muted_reminders' => 'Réglé par le propriétaire dans son espace.']);
        // L'accord photos, lui, reste noté par la clinique
        $this->putJson("/api/v1/clients/{$this->client->id}", ['photo_consent' => true], $this->enTetes($this->jeton))->assertOk();
    }

    public function test_le_proprietaire_coupe_un_type_de_rappel_dans_son_espace(): void
    {
        ['user' => $lea, 'jeton' => $jetonEspace] = $this->proprietaireAvecJeton();
        $this->client->update(['user_id' => $lea->id]);

        $this->putJson('/api/v1/espace/preferences', ['muted_reminders' => ['quotes', 'checkups']], $this->enTetes($jetonEspace))
            ->assertOk()->assertJsonPath('data.muted_reminders', ['checkups', 'quotes']);
        $this->putJson('/api/v1/espace/preferences', ['muted_reminders' => ['lune']], $this->enTetes($jetonEspace))
            ->assertUnprocessable()->assertJsonValidationErrors('muted_reminders.0');
    }
}
