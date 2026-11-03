<?php

namespace Tests\Feature\Patients;

use App\Enums\ModaliteImagerie;
use App\Enums\StatutAnalyse;
use App\Enums\StatutOrdonnance;
use App\Enums\StatutRendezVous;
use App\Models\Analyse;
use App\Models\AnalysePanel;
use App\Models\AnalyseResultat;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\CompteRendu;
use App\Models\Document;
use App\Models\Employe;
use App\Models\ExamenImagerie;
use App\Models\Ordonnance;
use App\Models\OrdonnanceLigne;
use App\Models\PanelLabo;
use App\Models\Patient;
use App\Models\Pesee;
use App\Models\Produit;
use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use App\Models\RendezVous;
use App\Models\Vaccination;
use App\Models\Visite;
use App\Support\TreatmentDuration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\TestCase;

/**
 * Le dossier patient, proposition B « tout sur un fil » (choix de Jim,
 * 03.11.2026) : toute l'histoire de l'animal sur un seul fil, du plus
 * récent au plus ancien, ce qui vient en tête ; des filtres à la place des
 * onglets ; le traitement en cours et ses jours ; le code ATC des
 * médicaments (mise en garde des allergies) ; le poids de forme saisi sur
 * la fiche. Chaque test suit une case de notes/todo.md (dépôt web).
 */
class PatientTimelineTest extends TestCase
{
    use EmetDesJetonsApi;
    use RefreshDatabase;

    private Clinique $clinique;

    private Patient $bourbon;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(10, 0));
        $this->clinique = Clinique::factory()->create(['nom' => 'Deux Rives']);
        $client = Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id, 'prenom' => 'Claire', 'nom' => 'Borel']);
        $this->bourbon = Patient::factory()->for($client)->create(['organisation_id' => $client->organisation_id, 'nom' => 'Bourbon', 'espece' => 'CH']);
        ['jeton' => $this->jeton] = $this->administrateurAvecJeton($this->clinique);
    }

    private function fil(string $requete = ''): array
    {
        return $this->getJson("/api/v1/patients/{$this->bourbon->id}/fil".$requete, $this->enTetes($this->jeton))->assertOk()->json();
    }

    private function ordonnance(string $signeeLe, array $lignes, StatutOrdonnance $statut = StatutOrdonnance::Active, ?Produit $produit = null): Ordonnance
    {
        $ordonnance = Ordonnance::factory()->create([
            'clinique_id' => $this->clinique->id,
            'organisation_id' => $this->bourbon->organisation_id,
            'patient_id' => $this->bourbon->id,
            'signee_le' => $signeeLe,
            'statut' => $statut,
        ]);
        foreach ($lignes as $duree) {
            OrdonnanceLigne::factory()->create([
                'ordonnance_id' => $ordonnance->id,
                'produit_id' => ($produit ?? Produit::factory()->medicament()->create(['organisation_id' => $this->bourbon->organisation_id]))->id,
                'duree' => $duree,
            ]);
        }

        return $ordonnance;
    }

    /** Case : visites, vaccins, pesées, prescriptions, examens et documents sur le fil */
    public function test_le_fil_raconte_toute_l_histoire_du_plus_recent_au_plus_ancien(): void
    {
        $rdv = RendezVous::factory()->pourPatient($this->bourbon)->create(['clinique_id' => $this->clinique->id, 'status' => StatutRendezVous::Fini, 'reason' => 'Boiterie antérieure gauche', 'starts_at' => now()->subDays(22), 'ends_at' => now()->subDays(22)->addMinutes(30)]);
        CompteRendu::factory()->create(['rendez_vous_id' => $rdv->id, 'a' => 'Arthrose du coude gauche', 'signe_le' => now()->subDays(22)]);
        Visite::factory()->create(['patient_id' => $this->bourbon->id, 'visite_le' => now()->subYears(2), 'motif' => 'Contrôle annuel']);
        Vaccination::factory()->create(['patient_id' => $this->bourbon->id, 'nom' => 'Rage', 'marque' => 'Rabisin', 'lot' => 'R2201', 'injectee_le' => now()->subDays(120)->toDateString(), 'echeance_le' => now()->addYears(2)->toDateString()]);
        Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 28.2, 'pesee_le' => now()->subDays(120)->toDateString()]);
        Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 28.4, 'pesee_le' => now()->subDays(22)->toDateString()]);
        $this->ordonnance(now()->subDays(12)->toDateString(), ['21 jours']);
        ExamenImagerie::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->bourbon->id, 'modalite' => ModaliteImagerie::Radiographie, 'libelle' => 'Coude gauche', 'realise_le' => now()->subDays(22)->setTime(11, 0)]);
        $analyse = Analyse::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->bourbon->id, 'statut' => StatutAnalyse::Valide, 'preleve_le' => now()->subDays(22)->setTime(10, 30)]);
        $labo = PanelLabo::factory()->create(['organisation_id' => $this->bourbon->organisation_id, 'code' => 'bio', 'nom' => 'Biochimie']);
        $panel = AnalysePanel::query()->create(['analyse_id' => $analyse->id, 'panel_labo_id' => $labo->id, 'code' => 'bio', 'nom' => 'Biochimie', 'tube' => 'SEC']);
        AnalyseResultat::query()->create(['analyse_id' => $analyse->id, 'analyse_panel_id' => $panel->id, 'position' => 1, 'parametre' => 'Créatinine', 'unite' => 'µmol/l', 'borne_basse' => 44, 'borne_haute' => 133, 'valeur' => 142, 'drapeau' => 'H']);
        AnalyseResultat::query()->create(['analyse_id' => $analyse->id, 'analyse_panel_id' => $panel->id, 'position' => 2, 'parametre' => 'Urée', 'unite' => 'mmol/l', 'borne_basse' => 2.5, 'borne_haute' => 9.6, 'valeur' => 9.1, 'drapeau' => null]);
        Document::factory()->create(['patient_id' => $this->bourbon->id, 'nom' => 'Rapport de l’ophtalmologue', 'type' => 'rapport', 'etabli_le' => now()->subMonths(8)->toDateString()]);

        $items = collect($this->fil()['data'])->where('a_venir', false)->values();

        $this->assertEqualsCanonicalizing(['visite', 'vaccin', 'poids', 'prescription', 'examen', 'document'], $items->pluck('type')->unique()->values()->all());
        // Du plus récent au plus ancien
        $dates = $items->pluck('date')->map(fn ($d) => strtotime($d))->all();
        $tries = $dates;
        rsort($tries);
        $this->assertSame($tries, $dates);
        // Chaque ligne a un titre et ce qu'on en sait
        $visite = $items->firstWhere('source', 'rdv');
        $this->assertSame('Boiterie antérieure gauche', $visite['titre']);
        $this->assertSame($rdv->id, $visite['rendez_vous_id']);
        $this->assertTrue($visite['compte_rendu']['signe']);
        $this->assertStringContainsString('Arthrose du coude gauche', $visite['detail']);
        $this->assertSame('Contrôle annuel', $items->firstWhere('source', 'visite')['titre']);
        $vaccin = $items->firstWhere('type', 'vaccin');
        $this->assertSame('R2201', $vaccin['lot']);
        $analyseLigne = $items->firstWhere('source', 'analyse');
        $this->assertSame(1, $analyseLigne['hors_normes']);
        $this->assertSame('DX', $items->firstWhere('source', 'imagerie')['modalite']);
        $this->assertSame('rapport', $items->firstWhere('type', 'document')['document_type']);
    }

    /** Case : ce qui vient en tête (prochain rappel) */
    public function test_ce_qui_vient_est_en_tete(): void
    {
        Vaccination::factory()->create(['patient_id' => $this->bourbon->id, 'nom' => 'Rage', 'marque' => 'Rabisin', 'injectee_le' => now()->subYears(3)->addDays(21)->toDateString(), 'echeance_le' => now()->addDays(21)->toDateString()]);
        RendezVous::factory()->pourPatient($this->bourbon)->create(['clinique_id' => $this->clinique->id, 'status' => StatutRendezVous::Prevu, 'reason' => 'Contrôle', 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addMinutes(20)]);
        Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 28.4, 'pesee_le' => now()->subDay()->toDateString()]);

        $data = $this->fil()['data'];

        $this->assertTrue($data[0]['a_venir']);
        $this->assertTrue($data[1]['a_venir']);
        // Le plus proche d'abord : le rendez-vous dans 5 jours, puis le rappel dans 3 semaines
        $this->assertSame('visite', $data[0]['type']);
        $this->assertSame('vaccin', $data[1]['type']);
        $this->assertStringContainsString('Rappel', $data[1]['titre']);
        $this->assertSame(now()->addDays(21)->toDateString(), $data[1]['echeance_le']);
        $this->assertFalse($data[2]['a_venir']);
        // Un filtre ne garde que ce qui le concerne en tête
        $this->assertSame(['vaccin'], collect($this->fil('?type=vaccin')['data'])->where('a_venir', true)->pluck('type')->values()->all());
        $this->assertCount(0, collect($this->fil('?type=poids')['data'])->where('a_venir', true));
    }

    /** Case : des filtres remplacent les onglets */
    public function test_le_fil_se_filtre_par_type(): void
    {
        Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 28.4, 'pesee_le' => now()->subDay()->toDateString()]);
        Document::factory()->create(['patient_id' => $this->bourbon->id, 'etabli_le' => now()->subDays(2)->toDateString()]);

        $this->assertSame(['poids'], collect($this->fil('?type=poids')['data'])->pluck('type')->unique()->values()->all());
        $this->assertSame(['document'], collect($this->fil('?type=document')['data'])->pluck('type')->unique()->values()->all());
        $this->getJson("/api/v1/patients/{$this->bourbon->id}/fil?type=facture", $this->enTetes($this->jeton))->assertUnprocessable();
    }

    /** Case : toute l'histoire sur un seul fil (rien de perdu d'une page à l'autre) */
    public function test_le_fil_se_pagine_sans_rien_perdre(): void
    {
        // 34 pesées le même jour (une saisie en série), à cheval sur la page, et des visites
        foreach (range(1, 34) as $i) {
            Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 20 + $i / 10, 'pesee_le' => now()->subDays(10)->toDateString()]);
        }
        foreach (range(1, 12) as $i) {
            Visite::factory()->create(['patient_id' => $this->bourbon->id, 'visite_le' => now()->subDays(3 + $i * 3)->toDateString()]);
        }

        $premiere = $this->fil();
        $this->assertCount(30, $premiere['data']);
        $this->assertNotNull($premiere['meta']['suivant']);

        $vus = $this->toutLeFil();
        $this->assertCount(46, $vus);
        $this->assertCount(46, $vus->unique());
    }

    /** Tout le fil, page après page, en suivant `meta.suivant` et `meta.sauter`. */
    private function toutLeFil(): Collection
    {
        $vus = collect();
        $curseur = '';
        do {
            $page = $this->fil($curseur);
            $vus = $vus->merge(collect($page['data'])->map(fn ($x) => $x['source'].':'.$x['id']));
            $suivant = $page['meta']['suivant'];
            $curseur = $suivant ? '?'.http_build_query(['avant' => $suivant, 'sauter' => $page['meta']['sauter']]) : '';
        } while ($suivant !== null && $vus->count() < 300);

        return $vus;
    }

    /** Case : filtre Poids, les pesées (l'écart avec la précédente, servi) */
    public function test_la_pesee_dit_l_ecart_avec_la_precedente(): void
    {
        Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 28.2, 'pesee_le' => '2026-07-03']);
        Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 28.4, 'pesee_le' => '2026-10-12']);

        $pesees = collect($this->fil('?type=poids')['data']);

        $this->assertEquals(28.4, $pesees[0]['poids_kg']);
        $this->assertEquals(0.2, $pesees[0]['ecart_kg']);
        $this->assertSame('2026-07-03', $pesees[0]['precedente_le']);
        $this->assertNull($pesees[1]['ecart_kg']);
    }

    /** Case : filtre Prescriptions, le traitement en cours (jours faits sur jours prévus) */
    public function test_le_traitement_en_cours_compte_ses_jours(): void
    {
        $enCours = $this->ordonnance(now()->subDays(12)->toDateString(), ['21 jours', '3 semaines']);
        $fini = $this->ordonnance(now()->subDays(20)->toDateString(), ['7 jours']);
        $besoin = $this->ordonnance(now()->subDays(2)->toDateString(), ['Selon besoin']);
        $close = $this->ordonnance(now()->subDays(1)->toDateString(), ['10 jours'], StatutOrdonnance::Cloturee);

        $lignes = collect($this->fil('?type=prescription')['data'])->keyBy('ordonnance_id');

        $this->assertSame(13, $lignes[$enCours->id]['jour']);
        $this->assertSame(21, $lignes[$enCours->id]['jours_prevus']);
        $this->assertTrue($lignes[$enCours->id]['en_cours']);
        $this->assertFalse($lignes[$fini->id]['en_cours']);
        $this->assertNull($lignes[$besoin->id]['jours_prevus']);
        $this->assertTrue($lignes[$besoin->id]['en_cours']);
        $this->assertFalse($lignes[$close->id]['en_cours']);
    }

    /** Case : la mise en garde quand un médicament touche une allergie notée (le code ATC du produit, servi) */
    public function test_le_medicament_porte_son_code_atc(): void
    {
        $meloxicam = Produit::factory()->medicament()->create(['organisation_id' => $this->bourbon->organisation_id, 'nom' => 'Metacam 1,5 mg/ml', 'atc_code' => 'QM01AC06']);
        $ordonnance = $this->ordonnance(now()->subDays(3)->toDateString(), ['21 jours'], StatutOrdonnance::Active, $meloxicam);

        $ligne = collect($this->fil('?type=prescription')['data'])->firstWhere('ordonnance_id', $ordonnance->id)['lignes'][0];
        $this->assertSame('QM01AC06', $ligne['atc_code']);
        $this->assertSame('Metacam 1,5 mg/ml', $ligne['produit_nom']);

        // Le registre et le stock le servent aussi
        $this->getJson("/api/v1/ordonnances/{$ordonnance->id}", $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.lignes.0.atc_code', 'QM01AC06');
        $this->getJson("/api/v1/stocks/produits/{$meloxicam->id}", $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.atc_code', 'QM01AC06');
    }

    /** Le code ATC se saisit à la création de l'article, ou vient du catalogue par le code-barres */
    public function test_l_article_recoit_son_code_atc(): void
    {
        $saisi = $this->postJson('/api/v1/stocks/produits', ['nom' => 'Amoxicilline 250 mg', 'categorie' => 'med', 'prix' => 12, 'atc_code' => 'qj01 ca04'], $this->enTetes($this->jeton))->assertCreated();
        $this->assertSame('QJ01CA04', $saisi->json('data.atc_code'));
        $this->postJson('/api/v1/stocks/produits', ['nom' => 'X', 'categorie' => 'med', 'prix' => 12, 'atc_code' => 'pas un code'], $this->enTetes($this->jeton))->assertUnprocessable();

        $catalogue = ProduitVeterinaire::query()->create(['numero_autorisation' => '65432', 'nom' => 'Metacam 1,5 mg/ml', 'nom_court' => 'Metacam', 'categorie' => 'medicament', 'atc_code' => 'QM01AC06']);
        ProduitEmballage::query()->create(['produit_id' => $catalogue->id, 'numero_emballage' => '65432001', 'gtin' => '7680654320014']);
        $parCodeBarres = $this->postJson('/api/v1/stocks/produits', ['nom' => 'Metacam', 'categorie' => 'med', 'prix' => 30, 'ean' => '7680654320014'], $this->enTetes($this->jeton))->assertCreated();
        $this->assertSame('QM01AC06', $parCodeBarres->json('data.atc_code'));
    }

    /** Case : filtre Poids, la bande du poids de forme (saisie par le vétérinaire sur la fiche) */
    public function test_le_poids_de_forme_se_saisit_sur_la_fiche(): void
    {
        $url = "/api/v1/patients/{$this->bourbon->id}";
        $this->getJson($url, $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.healthy_weight_min_kg', null);

        $this->patchJson($url, ['healthy_weight_min_kg' => 25, 'healthy_weight_max_kg' => 28], $this->enTetes($this->jeton))->assertOk();
        $fiche = $this->getJson($url, $this->enTetes($this->jeton))->assertOk();
        $this->assertEquals(25, $fiche->json('data.healthy_weight_min_kg'));
        $this->assertEquals(28, $fiche->json('data.healthy_weight_max_kg'));

        // Le haut sous le bas, ou une seule borne : refusé
        $this->patchJson($url, ['healthy_weight_min_kg' => 28, 'healthy_weight_max_kg' => 25], $this->enTetes($this->jeton))->assertUnprocessable();
        $this->patchJson($url, ['healthy_weight_min_kg' => 26], $this->enTetes($this->jeton))->assertUnprocessable();
        // Effacée : plus de bande
        $this->patchJson($url, ['healthy_weight_min_kg' => null, 'healthy_weight_max_kg' => null], $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.healthy_weight_max_kg', null);
    }

    public function test_une_autre_organisation_ne_lit_pas_le_fil(): void
    {
        Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'pesee_le' => now()->subDay()->toDateString()]);
        ['jeton' => $autre] = $this->administrateurAvecJeton(Clinique::factory()->create());

        $reponse = $this->getJson("/api/v1/patients/{$this->bourbon->id}/fil", $this->enTetes($autre));
        $this->assertContains($reponse->status(), [403, 404]);
    }

    public function test_les_gestes_d_une_ligne_sont_servis(): void
    {
        $employe = Employe::factory()->dans($this->clinique)->create();
        $vaccin = Vaccination::factory()->create(['patient_id' => $this->bourbon->id, 'employe_id' => $employe->id, 'injectee_le' => now()->subDays(4)->toDateString()]);
        $pesee = Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'pesee_le' => now()->subDays(3)->toDateString()]);
        $document = Document::factory()->create(['patient_id' => $this->bourbon->id, 'etabli_le' => now()->subDays(2)->toDateString()]);

        $items = collect($this->fil()['data']);

        $this->assertSame($vaccin->id, $items->firstWhere('source', 'vaccination')['vaccination_id']);
        $this->assertSame($employe->id, $items->firstWhere('source', 'vaccination')['employe_id']);
        $this->assertSame($pesee->id, $items->firstWhere('type', 'poids')['pesee_id']);
        $this->assertSame($document->id, $items->firstWhere('type', 'document')['document_id']);
    }

    /** M4 : une ordonnance sans durée lisible est en cours tant qu'elle est valable (30 jours sans date de validité), puis terminée */
    public function test_une_ordonnance_sans_duree_lisible_finit_a_sa_validite(): void
    {
        $recente = $this->ordonnance(now()->subDays(10)->toDateString(), ['Selon besoin']);
        $ancienne = $this->ordonnance(now()->subDays(40)->toDateString(), ['jusqu’au contrôle']);

        $lignes = collect($this->fil('?type=prescription')['data'])->keyBy('ordonnance_id');

        $this->assertTrue($lignes[$recente->id]['en_cours']);
        $this->assertNull($lignes[$recente->id]['jours_prevus']);
        $this->assertSame(now()->subDays(10)->addDays(30)->toDateString(), $lignes[$recente->id]['valable_jusqu_au']);
        $this->assertFalse($lignes[$ancienne->id]['en_cours']);
        $this->assertStringContainsString('terminée', $lignes[$ancienne->id]['detail']);
    }

    /** F5 : un rappel de vaccin échu vient en tête du fil, marqué en retard */
    public function test_un_rappel_echu_vient_en_tete_en_retard(): void
    {
        Vaccination::factory()->create(['patient_id' => $this->bourbon->id, 'nom' => 'Leptospirose', 'marque' => 'Nobivac L4', 'injectee_le' => now()->subYear()->subDays(20)->toDateString(), 'echeance_le' => now()->subDays(20)->toDateString()]);
        Vaccination::factory()->create(['patient_id' => $this->bourbon->id, 'nom' => 'Rage', 'marque' => 'Rabisin', 'injectee_le' => now()->subYears(2)->toDateString(), 'echeance_le' => now()->addDays(30)->toDateString()]);

        $tete = collect($this->fil()['data'])->where('a_venir', true)->values();

        $this->assertCount(2, $tete);
        $this->assertTrue($tete[0]['en_retard']);
        $this->assertStringContainsString('Leptospirose', $tete[0]['titre']);
        $this->assertFalse($tete[1]['en_retard']);
    }

    /** F4 : la conclusion d'un compte rendu ne se lit sur le fil que s'il est signé */
    public function test_la_conclusion_seulement_signee(): void
    {
        $rdv = RendezVous::factory()->pourPatient($this->bourbon)->create(['clinique_id' => $this->clinique->id, 'status' => StatutRendezVous::Fini, 'reason' => 'Toux', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addMinutes(20)]);
        CompteRendu::factory()->create(['rendez_vous_id' => $rdv->id, 'a' => 'Brouillon de conclusion', 'signe_le' => null]);

        $ligne = collect($this->fil('?type=visite')['data'])->firstWhere('rendez_vous_id', $rdv->id);

        $this->assertStringNotContainsString('Brouillon de conclusion', $ligne['detail']);
        $this->assertFalse($ligne['compte_rendu']['signe']);
    }

    /** F11 : les valences de chaque injection sur sa ligne */
    public function test_l_injection_dit_ses_valences(): void
    {
        Vaccination::factory()->create(['patient_id' => $this->bourbon->id, 'nom' => 'CHPPi', 'marque' => 'Nobivac DHPPi', 'valences' => ['parvovirose', 'leptospirose'], 'injectee_le' => now()->subDays(3)->toDateString(), 'echeance_le' => now()->addYear()->toDateString()]);

        $ligne = collect($this->fil('?type=vaccin')['data'])->firstWhere('source', 'vaccination');

        $this->assertSame(['Parvovirose', 'Leptospirose'], $ligne['valences']);
        $this->assertStringContainsString('Parvovirose, Leptospirose', $ligne['detail']);
    }

    /** F6 : où il en est par rapport à son poids de forme, servi */
    public function test_la_position_par_rapport_au_poids_de_forme_est_servie(): void
    {
        $url = "/api/v1/patients/{$this->bourbon->id}";
        Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 29.1, 'pesee_le' => now()->subDay()->toDateString()]);
        $this->getJson($url, $this->enTetes($this->jeton))->assertOk()->assertJsonPath('data.healthy_weight_position', null);

        $this->patchJson($url, ['healthy_weight_min_kg' => 25, 'healthy_weight_max_kg' => 28], $this->enTetes($this->jeton))->assertOk();
        $this->getJson($url, $this->enTetes($this->jeton))->assertJsonPath('data.healthy_weight_position', 'above');
        $this->patchJson($url, ['healthy_weight_min_kg' => 28, 'healthy_weight_max_kg' => 30], $this->enTetes($this->jeton))->assertOk();
        $this->getJson($url, $this->enTetes($this->jeton))->assertJsonPath('data.healthy_weight_position', 'within');
        $this->patchJson($url, ['healthy_weight_min_kg' => 30, 'healthy_weight_max_kg' => 32], $this->enTetes($this->jeton))->assertOk();
        $this->getJson($url, $this->enTetes($this->jeton))->assertJsonPath('data.healthy_weight_position', 'below');
    }

    /** F6 : l'enregistrement de la bande sert la position à jour */
    public function test_le_patch_de_la_bande_sert_la_position(): void
    {
        Pesee::factory()->create(['patient_id' => $this->bourbon->id, 'poids_kg' => 29.1, 'pesee_le' => now()->subDay()->toDateString()]);

        $this->patchJson("/api/v1/patients/{$this->bourbon->id}", ['healthy_weight_min_kg' => 25, 'healthy_weight_max_kg' => 28], $this->enTetes($this->jeton))
            ->assertOk()->assertJsonPath('data.healthy_weight_position', 'above');
    }

    /** « pendant 10 jours » et « 7 à 10 jours » se lisent : 10 jours */
    public function test_les_durees_ecrites_autrement_se_lisent(): void
    {
        $this->assertSame(10, TreatmentDuration::days('pendant 10 jours'));
        $this->assertSame(10, TreatmentDuration::days('7 à 10 jours'));
        $this->assertSame(10, TreatmentDuration::days('7-10 jours'));
        $this->assertSame(14, TreatmentDuration::days('Pendant 2 semaines'));
        $this->assertNull(TreatmentDuration::days('Selon besoin'));
    }
}
