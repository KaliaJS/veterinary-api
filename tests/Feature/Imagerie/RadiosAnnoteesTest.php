<?php

namespace Tests\Feature\Imagerie;

use App\Models\AnnotationImagerie;
use App\Models\ClicheDicom;
use App\Models\Client;
use App\Models\Clinique;
use App\Models\Document;
use App\Models\Employe;
use App\Models\ExamenImagerie;
use App\Models\Patient;
use App\Models\Pesee;
use App\Support\Dicom\LecteurDicom;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EmetDesJetonsApi;
use Tests\Concerns\FabriqueDicom;
use Tests\TestCase;

/**
 * Radios annotées (décision de Jim, 28.10.2026, « fais tout maintenant ») :
 * l'original DICOM gardé tel quel, l'espèce rattachée, région et incidence
 * en listes fixes pré-remplies depuis le DICOM et validées par un humain,
 * annotations dessinées gardées à part en pixels de l'image d'origine,
 * étiquettes d'une liste fixe, confirmations après coup, accord de
 * l'organisation pour l'entraînement.
 */
class RadiosAnnoteesTest extends TestCase
{
    use EmetDesJetonsApi;
    use FabriqueDicom;
    use RefreshDatabase;

    private Clinique $clinique;

    private Employe $employe;

    private string $jeton;

    private Patient $patient;

    private ExamenImagerie $examen;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('prive');
        $this->clinique = Clinique::factory()->create();
        ['employe' => $this->employe, 'jeton' => $this->jeton] = $this->employeAvecJeton($this->clinique);
        $this->patient = Patient::factory()
            ->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))
            ->create(['espece' => 'CH', 'race' => 'Berger allemand']);
        Pesee::factory()->create(['patient_id' => $this->patient->id, 'poids_kg' => 31.4, 'pesee_le' => now()->subDays(3)]);
        $this->examen = ExamenImagerie::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'prescripteur_id' => $this->employe->id, 'realise_le' => now()]);
    }

    /** @return array<string, string> */
    private function en(): array
    {
        return $this->enTetes($this->jeton);
    }

    private function envoyer(string $octets, string $nom = 'thorax.dcm', ?ExamenImagerie $examen = null): TestResponse
    {
        $examen ??= $this->examen;

        return $this->post("/api/v1/imagerie/examens/{$examen->id}/cliches/fichier", ['fichier' => UploadedFile::fake()->createWithContent($nom, $octets)], $this->en() + ['Accept' => 'application/json']);
    }

    private function base(): string
    {
        return "/api/v1/patients/{$this->patient->id}/imagerie/{$this->examen->id}";
    }

    private function cliche(): ClicheDicom
    {
        $this->envoyer($this->dicom(['region' => 'CHEST', 'incidence' => 'RL', 'lignes' => 8, 'colonnes' => 10]))->assertCreated();

        return ClicheDicom::query()->latest('id')->firstOrFail();
    }

    // ————— 1. L'original gardé tel quel —————

    public function test_l_original_dicom_est_garde_tel_quel_et_relu_a_l_octet_pres(): void
    {
        $octets = $this->dicom(['region' => 'CHEST', 'incidence' => 'RL']);

        $cliche = $this->envoyer($octets)->assertCreated()->assertJsonPath('data.a_fichier', true)->json('data');

        $relu = $this->get($this->base()."/cliches/{$cliche['id']}/fichier", $this->en());
        $relu->assertOk();
        $this->assertSame($octets, $relu->streamedContent());
        $this->assertSame(hash('sha256', $octets), ClicheDicom::query()->findOrFail($cliche['id'])->empreinte_sha256);
        $this->assertSame(strlen($octets), $cliche['taille_octets']);
    }

    public function test_un_fichier_qui_n_est_pas_un_dicom_est_refuse(): void
    {
        $this->envoyer('ceci n’est pas une radio', 'photo.dcm')->assertUnprocessable()->assertJsonValidationErrors('fichier');
        $this->envoyer($this->dicom([], avecPixels: false))->assertUnprocessable()->assertJsonValidationErrors('fichier');
        $this->assertSame(0, ClicheDicom::query()->count());
    }

    public function test_le_meme_cliche_envoye_deux_fois_n_est_ni_double_ni_remplace(): void
    {
        $octets = $this->dicom();
        $premier = $this->envoyer($octets)->assertCreated()->json('data.id');

        $this->envoyer($octets)->assertOk()->assertJsonPath('data.id', $premier);

        $this->assertSame(1, ClicheDicom::query()->count());
    }

    public function test_un_examen_signe_ne_recoit_plus_de_radio(): void
    {
        $signe = ExamenImagerie::factory()->signe()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'prescripteur_id' => $this->employe->id]);

        $this->envoyer($this->dicom(), examen: $signe)->assertUnprocessable();
    }

    public function test_le_lecteur_lit_le_vr_implicite_et_saute_les_sequences_de_longueur_indefinie(): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'dcm');
        file_put_contents($chemin, $this->dicom(['region' => 'ABDOMEN', 'incidence' => 'VD', 'lignes' => 12, 'colonnes' => 16, 'images' => 1], '1.2.840.10008.1.2'));

        $lu = LecteurDicom::lire($chemin);

        $this->assertSame('1.2.826.0.1.3680043.8.498.1001', $lu['sop_instance_uid']);
        $this->assertSame(['ABDOMEN', 'VD', 12, 16, 1, true], [$lu['body_part'], $lu['view_position'], $lu['lignes'], $lu['colonnes'], $lu['images'], $lu['pixels']]);
        unlink($chemin);
    }

    // ————— 2. L'espèce rattachée —————

    public function test_l_espece_la_race_et_le_poids_de_l_animal_sont_rattaches_au_cliche(): void
    {
        $cliche = $this->envoyer($this->dicom())->assertCreated()->json('data');

        $this->assertSame(['CH', 'Berger allemand', 31.4], [$cliche['espece'], $cliche['race'], $cliche['poids_kg']]);
        // Le cliché garde l'espèce du jour de la radio, même si la fiche change ensuite
        $this->patient->update(['race' => 'Croisé']);
        $this->assertSame('Berger allemand', ClicheDicom::query()->findOrFail($cliche['id'])->race);
    }

    // ————— 3. Région et incidence en listes fixes, pré-remplies, validées —————

    public function test_region_et_incidence_sont_pre_remplies_depuis_le_dicom_et_a_valider(): void
    {
        $cliche = $this->envoyer($this->dicom(['region' => 'CHEST', 'incidence' => 'RL']))->assertCreated()->json('data');

        $this->assertSame(['thorax', 'lateral_droit', false], [$cliche['region'], $cliche['incidence'], $cliche['reperes_valides']]);
        $this->assertSame(['body_part' => 'CHEST', 'view_position' => 'RL', 'lateralite' => null], $cliche['reperes_dicom']);
    }

    public function test_sans_tags_utiles_rien_n_est_devine(): void
    {
        $cliche = $this->envoyer($this->dicom(['region' => 'LEG', 'incidence' => 'LAT']))->assertCreated()->json('data');

        $this->assertNull($cliche['region']);
        $this->assertNull($cliche['incidence']);
    }

    public function test_un_humain_valide_ou_corrige_region_et_incidence_dans_les_listes_fixes(): void
    {
        $cliche = $this->cliche();

        $this->patchJson($this->base()."/cliches/{$cliche->id}", ['region' => 'thorax', 'incidence' => 'lateral_gauche'], $this->en())->assertOk()
            ->assertJsonPath('data.incidence', 'lateral_gauche')->assertJsonPath('data.reperes_valides', true);
        $this->assertNotNull($cliche->refresh()->reperes_valides_le);
        $this->assertSame($this->employe->user_id, $cliche->reperes_valides_par);

        $this->patchJson($this->base()."/cliches/{$cliche->id}", ['region' => 'poitrine', 'incidence' => 'de côté'], $this->en())
            ->assertUnprocessable()->assertJsonValidationErrors(['region', 'incidence']);
    }

    public function test_les_listes_fixes_sont_servies(): void
    {
        $listes = $this->getJson('/api/v1/referentiels/imagerie', $this->en())->assertOk()->json('data');

        $this->assertContains('thorax', array_column($listes['regions'], 'code'));
        $this->assertContains('lateral_droit', array_column($listes['incidences'], 'code'));
        $this->assertContains('corps_etranger_intestinal', array_column($listes['etiquettes'], 'code'));
        $this->assertSame(['rectangle', 'ellipse', 'point', 'fleche', 'longueur', 'angle', 'contour'], array_column($listes['types'], 'code'));
        $this->assertSame(['sur', 'suspect'], array_column($listes['certitudes'], 'code'));
        $this->assertContains('histologie', array_column($listes['sources'], 'code'));
    }

    // ————— 4 et 5. Annotations à part, en pixels de l'image, étiquette, certitude, auteur —————

    public function test_une_annotation_est_gardee_a_part_en_pixels_de_l_image_sans_toucher_au_dicom(): void
    {
        $cliche = $this->cliche();
        $avant = Storage::disk('prive')->get($cliche->chemin);

        $annotation = $this->postJson($this->base()."/cliches/{$cliche->id}/annotations", [
            'type' => 'rectangle', 'points' => [[2, 3], [6.5, 7]], 'image' => 0,
            'etiquette' => 'corps_etranger_intestinal', 'certitude' => 'sur',
        ], $this->en())->assertCreated()->json('data');

        $this->assertSame([[2, 3], [6.5, 7]], $annotation['points']);
        $this->assertSame(['corps_etranger_intestinal', 'sur', $this->employe->user_id], [$annotation['etiquette'], $annotation['certitude'], $annotation['auteur']['id']]);
        $this->assertNotNull($annotation['cree_le']);
        $this->assertSame($avant, Storage::disk('prive')->get($cliche->chemin));
        $this->assertSame($cliche->sop_instance_uid, AnnotationImagerie::query()->sole()->cliche->sop_instance_uid);
    }

    public function test_chaque_type_a_son_nombre_de_points(): void
    {
        $cliche = $this->cliche();
        $poser = fn (string $type, array $points) => $this->postJson($this->base()."/cliches/{$cliche->id}/annotations", ['type' => $type, 'points' => $points, 'etiquette' => 'fracture', 'certitude' => 'suspect'], $this->en());

        $poser('point', [[1, 1]])->assertCreated();
        $poser('longueur', [[1, 1], [5, 5]])->assertCreated();
        $poser('angle', [[1, 1], [4, 4], [8, 1]])->assertCreated();
        $poser('contour', [[1, 1], [4, 1], [4, 4]])->assertCreated();
        $poser('angle', [[1, 1], [4, 4]])->assertUnprocessable()->assertJsonValidationErrors('points');
        $poser('rectangle', [[1, 1]])->assertUnprocessable()->assertJsonValidationErrors('points');
    }

    public function test_les_points_restent_dans_l_image_et_l_etiquette_dans_la_liste(): void
    {
        $cliche = $this->cliche(); // 8 lignes × 10 colonnes
        $poser = fn (array $donnees) => $this->postJson($this->base()."/cliches/{$cliche->id}/annotations", $donnees + ['type' => 'point', 'points' => [[1, 1]], 'etiquette' => 'fracture', 'certitude' => 'sur'], $this->en());

        $poser(['points' => [[11, 1]]])->assertUnprocessable()->assertJsonValidationErrors('points');
        $poser(['points' => [[1, 9]]])->assertUnprocessable()->assertJsonValidationErrors('points');
        $poser(['image' => 1])->assertUnprocessable()->assertJsonValidationErrors('image');
        $poser(['etiquette' => 'truc bizarre'])->assertUnprocessable()->assertJsonValidationErrors('etiquette');
        $poser(['certitude' => 'peut-être'])->assertUnprocessable()->assertJsonValidationErrors('certitude');
        $this->assertSame(0, AnnotationImagerie::query()->count());
    }

    public function test_une_annotation_se_corrige_et_se_retire(): void
    {
        $cliche = $this->cliche();
        $id = $this->postJson($this->base()."/cliches/{$cliche->id}/annotations", ['type' => 'point', 'points' => [[1, 1]], 'etiquette' => 'fracture', 'certitude' => 'suspect'], $this->en())->json('data.id');

        $this->patchJson($this->base()."/cliches/{$cliche->id}/annotations/{$id}", ['certitude' => 'sur', 'etiquette' => 'luxation'], $this->en())->assertOk()
            ->assertJsonPath('data.certitude', 'sur')->assertJsonPath('data.etiquette', 'luxation');
        $this->getJson($this->base()."/cliches/{$cliche->id}/annotations", $this->en())->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson($this->base()."/cliches/{$cliche->id}/annotations/{$id}", [], $this->en())->assertNoContent();
        $this->assertSame(0, AnnotationImagerie::query()->count());
    }

    // ————— 6. Confirmations après coup —————

    public function test_un_diagnostic_se_confirme_apres_coup_avec_sa_source(): void
    {
        $document = Document::factory()->create(['patient_id' => $this->patient->id]);

        $confirmation = $this->postJson($this->base().'/confirmations', [
            'etiquette' => 'corps_etranger_intestinal', 'resultat' => 'confirme', 'source' => 'chirurgie',
            'constate_le' => now()->toDateString(), 'document_id' => $document->id, 'note' => 'Noyau de pêche retiré au jéjunum.',
        ], $this->en())->assertCreated()->json('data');

        $this->assertSame(['confirme', 'chirurgie', $document->id], [$confirmation['resultat'], $confirmation['source'], $confirmation['document_id']]);
        $this->postJson($this->base().'/confirmations', ['etiquette' => 'fracture', 'resultat' => 'infirme', 'source' => 'scanner', 'constate_le' => now()->toDateString()], $this->en())->assertCreated();
        $this->getJson($this->base().'/confirmations', $this->en())->assertOk()->assertJsonCount(2, 'data');
        $this->postJson($this->base().'/confirmations', ['etiquette' => 'fracture', 'resultat' => 'peut-être', 'source' => 'intuition', 'constate_le' => now()->toDateString()], $this->en())
            ->assertUnprocessable()->assertJsonValidationErrors(['resultat', 'source']);
    }

    public function test_une_confirmation_ne_vise_pas_le_document_d_un_autre_animal(): void
    {
        $autre = Document::factory()->create(['patient_id' => Patient::factory()->for(Client::factory()->create(['organisation_id' => $this->clinique->societe->organisation_id]))->create()->id]);

        $this->postJson($this->base().'/confirmations', ['etiquette' => 'fracture', 'resultat' => 'confirme', 'source' => 'histologie', 'constate_le' => now()->toDateString(), 'document_id' => $autre->id], $this->en())
            ->assertUnprocessable()->assertJsonValidationErrors('document_id');
    }

    public function test_un_diagnostic_confirme_dans_une_clinique_soeur_se_lit_dans_le_dossier(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $jetonSoeur] = $this->employeAvecJeton($soeur);

        $this->postJson($this->base().'/confirmations', ['etiquette' => 'fracture', 'resultat' => 'confirme', 'source' => 'chirurgie', 'constate_le' => now()->toDateString()], $this->enTetes($jetonSoeur))->assertCreated();
        $this->getJson($this->base().'/confirmations', $this->en())->assertOk()->assertJsonCount(1, 'data');
    }

    // ————— 7. Accord de l'organisation pour l'entraînement —————

    public function test_l_organisation_donne_et_retire_son_accord_date_pour_l_entrainement(): void
    {
        ['jeton' => $admin] = $this->administrateurAvecJeton($this->clinique);
        $this->getJson('/api/v1/organisation', $this->enTetes($admin))->assertOk()->assertJsonPath('data.entrainement_imagerie.accord', false);

        $this->putJson('/api/v1/organisation/entrainement-imagerie', ['accord' => true], $this->enTetes($admin))->assertOk()
            ->assertJsonPath('data.entrainement_imagerie.accord', true);
        $organisation = $this->clinique->societe->organisation->refresh();
        $this->assertNotNull($organisation->entrainement_consenti_le);

        $this->putJson('/api/v1/organisation/entrainement-imagerie', ['accord' => false], $this->enTetes($admin))->assertOk()
            ->assertJsonPath('data.entrainement_imagerie.accord', false);
        $this->assertNull($organisation->refresh()->entrainement_consenti_le);
    }

    public function test_sans_accord_aucune_image_n_est_utilisable_pour_l_entrainement(): void
    {
        $this->cliche();
        $this->assertSame(0, ClicheDicom::query()->entrainementPermis()->count());

        $this->clinique->societe->organisation->forceFill(['entrainement_consenti_le' => now()])->save();
        $this->assertSame(1, ClicheDicom::query()->entrainementPermis()->count());
    }

    public function test_seul_le_droit_organisation_donne_l_accord(): void
    {
        $this->putJson('/api/v1/organisation/entrainement-imagerie', ['accord' => true], $this->en())->assertForbidden();
    }

    // ————— 8. Lisible par l'équipe du dossier, jamais par une autre organisation —————

    public function test_une_autre_organisation_ne_lit_ni_le_fichier_ni_les_annotations(): void
    {
        ['jeton' => $etranger] = $this->employeAvecJeton(Clinique::factory()->create());
        $cliche = $this->cliche();

        $this->get($this->base()."/cliches/{$cliche->id}/fichier", $this->enTetes($etranger))->assertNotFound();
        $this->getJson($this->base()."/cliches/{$cliche->id}/annotations", $this->enTetes($etranger))->assertNotFound();
        $this->getJson($this->base().'/confirmations', $this->enTetes($etranger))->assertNotFound();
        $this->postJson($this->base()."/cliches/{$cliche->id}/annotations", ['type' => 'point', 'points' => [[1, 1]], 'etiquette' => 'fracture', 'certitude' => 'sur'], $this->enTetes($etranger))->assertNotFound();
    }

    public function test_un_cliche_d_un_autre_examen_ne_se_lit_pas_par_cet_examen(): void
    {
        $cliche = $this->cliche();
        $autreExamen = ExamenImagerie::factory()->create(['clinique_id' => $this->clinique->id, 'patient_id' => $this->patient->id, 'prescripteur_id' => $this->employe->id]);

        $this->get("/api/v1/patients/{$this->patient->id}/imagerie/{$autreExamen->id}/cliches/{$cliche->id}/fichier", $this->en())->assertNotFound();
    }

    public function test_le_dossier_d_une_clinique_soeur_lit_la_radio(): void
    {
        $cliche = $this->cliche();
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $jetonSoeur] = $this->employeAvecJeton($soeur);

        $this->get($this->base()."/cliches/{$cliche->id}/fichier", $this->enTetes($jetonSoeur))->assertOk();
        $this->getJson("/api/v1/patients/{$this->patient->id}/imagerie", $this->enTetes($jetonSoeur))->assertOk()
            ->assertJsonPath('data.0.cliches.0.a_fichier', true)->assertJsonPath('data.0.cliches.0.region', 'thorax');
    }

    // ————— Stockage : 1000 Go par organisation (décision de Jim, 28.10.2026) —————

    public function test_chaque_formule_offre_1000_go_de_stockage(): void
    {
        $offres = $this->getJson('/api/v1/public/offres')->assertOk()->json('data.formules');

        $this->assertSame([1000, 1000, 1000], array_map(fn (array $f): ?int => $f['limites']['stockage_go'], $offres));
    }

    public function test_les_radios_comptent_dans_le_stockage_de_l_organisation(): void
    {
        ['jeton' => $admin] = $this->administrateurAvecJeton($this->clinique);
        $octets = $this->dicom();
        $this->envoyer($octets)->assertCreated();

        $usage = $this->getJson('/api/v1/organisation', $this->enTetes($admin))->assertOk()->json('data.usage');

        $this->assertSame(strlen($octets), $usage['stockage_octets']);
        $this->assertEqualsWithDelta(strlen($octets) / 1e9, $usage['stockage_go'], 0.001);
    }

    // ————— Vérification, première passe —————

    /** D1 : 60 000 séquences imbriquées : la lecture s'arrête à la seizième, sans dévorer la mémoire (sans la limite : des centaines de Mo) */
    public function test_des_sequences_imbriquees_tres_profondes_ne_font_pas_exploser_le_lecteur(): void
    {
        $niveaux = 60000;
        $ouvre = pack('vv', 0x0008, 0x1140).'SQ'."\x00\x00".pack('V', 0xFFFFFFFF).pack('vvV', 0xFFFE, 0xE000, 0xFFFFFFFF);
        $ferme = pack('vvV', 0xFFFE, 0xE00D, 0).pack('vvV', 0xFFFE, 0xE0DD, 0);
        $octets = $this->dicom();
        $ici = strpos($octets, pack('vv', 0x0008, 0x0016));
        $chemin = tempnam(sys_get_temp_dir(), 'dcm');
        file_put_contents($chemin, substr($octets, 0, $ici).str_repeat($ouvre, $niveaux).str_repeat($ferme, $niveaux).substr($octets, $ici));

        $avant = memory_get_usage();
        $lu = LecteurDicom::lire($chemin);

        $this->assertLessThan(48 * 1024 * 1024, memory_get_usage() - $avant);
        // L'identifiant de l'en-tête de fichier reste lu ; les pixels ne sont pas atteints : refusé à l'envoi
        $this->assertSame('1.2.826.0.1.3680043.8.498.1001', $lu['sop_instance_uid']);
        $this->assertFalse($lu['pixels']);
        unlink($chemin);
    }

    /** D1 : un fichier coupé à n'importe quel octet est lu ou refusé, jamais une erreur */
    public function test_un_fichier_coupe_a_n_importe_quel_octet_ne_fait_jamais_d_erreur(): void
    {
        $octets = $this->dicom(['region' => 'CHEST', 'incidence' => 'RL'], '1.2.840.10008.1.2.1');
        $chemin = tempnam(sys_get_temp_dir(), 'dcm');
        for ($longueur = 0; $longueur < strlen($octets); $longueur++) {
            file_put_contents($chemin, substr($octets, 0, $longueur));
            LecteurDicom::lire($chemin);
        }
        unlink($chemin);
        $this->assertTrue(true);
    }

    /** D2 : un élément UN de longueur indéfinie (contenu en VR implicite, prévu par la norme) ne fait pas refuser la radio */
    public function test_un_element_un_de_longueur_indefinie_est_saute_selon_la_norme(): void
    {
        $octets = $this->dicom(['region' => 'ABDOMEN']);
        $un = pack('vv', 0x0009, 0x1010).'UN'."\x00\x00".pack('V', 0xFFFFFFFF)
            .pack('vvV', 0xFFFE, 0xE000, 0xFFFFFFFF).pack('vvV', 0x0009, 0x1011, 4).'ABCD'.pack('vvV', 0xFFFE, 0xE00D, 0).pack('vvV', 0xFFFE, 0xE0DD, 0);
        $position = strpos($octets, pack('vv', 0x0010, 0x0010));
        $octets = substr($octets, 0, $position).$un.substr($octets, $position);

        $this->envoyer($octets)->assertCreated()->assertJsonPath('data.region', 'abdomen');
    }

    /** D3 : un SOP Instance UID qui n'est pas un UID DICOM (chiffres et points, 64 au plus) est refusé, jamais écrasé ni tronqué */
    public function test_un_sop_qui_n_est_pas_un_uid_est_refuse(): void
    {
        $this->envoyer($this->dicom(['sop' => '1.2.a']))->assertUnprocessable()->assertJsonValidationErrors('fichier');
        $this->envoyer($this->dicom(['sop' => '1.2/../3']))->assertUnprocessable();
        $this->envoyer($this->dicom(['sop' => '1.'.str_repeat('2', 70)]))->assertUnprocessable();
        $this->assertSame(0, ClicheDicom::query()->count());
    }

    /** D4 : la même radio transmise à une clinique d'une autre organisation (référé) y est acceptée, sans rien révéler */
    public function test_la_meme_radio_dans_une_autre_organisation_est_acceptee(): void
    {
        $autre = Clinique::factory()->create();
        ['employe' => $employeAutre, 'jeton' => $jetonAutre] = $this->employeAvecJeton($autre);
        $patientAutre = Patient::factory()->for(Client::factory()->create(['organisation_id' => $autre->societe->organisation_id]))->create();
        $examenAutre = ExamenImagerie::factory()->create(['clinique_id' => $autre->id, 'patient_id' => $patientAutre->id, 'prescripteur_id' => $employeAutre->id]);
        $octets = $this->dicom();
        $this->envoyer($octets)->assertCreated();

        $this->post("/api/v1/imagerie/examens/{$examenAutre->id}/cliches/fichier", ['fichier' => UploadedFile::fake()->createWithContent('t.dcm', $octets)], $this->enTetes($jetonAutre) + ['Accept' => 'application/json'])
            ->assertCreated();
        $this->assertSame(2, ClicheDicom::query()->count());
    }

    /** D7 : un examen se résout par son patient : le patient d'une autre organisation ne lit pas la radio de cet examen */
    public function test_le_patient_d_une_autre_organisation_ne_lit_pas_nos_radios_par_l_identifiant_d_examen(): void
    {
        $autre = Clinique::factory()->create();
        ['jeton' => $jetonAutre] = $this->employeAvecJeton($autre);
        $patientAutre = Patient::factory()->for(Client::factory()->create(['organisation_id' => $autre->societe->organisation_id]))->create();
        $cliche = $this->cliche();

        $this->get("/api/v1/patients/{$patientAutre->id}/imagerie/{$this->examen->id}/cliches/{$cliche->id}/fichier", $this->enTetes($jetonAutre))->assertNotFound();
        $this->getJson("/api/v1/patients/{$patientAutre->id}/imagerie/{$this->examen->id}/confirmations", $this->enTetes($jetonAutre))->assertNotFound();
    }

    /** D8 : des champs mal formés répondent 422, jamais 500 */
    public function test_des_champs_mal_formes_repondent_422(): void
    {
        $cliche = $this->cliche();

        $this->postJson($this->base()."/cliches/{$cliche->id}/annotations", ['type' => ['rectangle'], 'points' => [[1, 1], [2, 2]], 'etiquette' => 'fracture', 'certitude' => 'sur'], $this->en())
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson($this->base()."/cliches/{$cliche->id}/annotations", ['type' => 'point', 'points' => [['1e0', 1]], 'etiquette' => 'fracture', 'certitude' => 'sur'], $this->en())
            ->assertUnprocessable()->assertJsonValidationErrors('points');
        $tronque = substr($this->dicom(), 0, 300);
        $this->envoyer($tronque)->assertUnprocessable();
        $this->postJson($this->base()."/cliches/{$cliche->id}/annotations", [], $this->en())->assertUnprocessable()->assertJsonValidationErrors(['type' => 'Choisissez un dessin.']);
    }

    /** D11 : le poids rattaché est celui de la dernière pesée faite au plus tard le jour de la radio */
    public function test_le_poids_est_celui_du_jour_de_la_radio(): void
    {
        $this->examen->update(['realise_le' => now()->subDays(10)]);
        Pesee::factory()->create(['patient_id' => $this->patient->id, 'poids_kg' => 29.0, 'pesee_le' => now()->subDays(12)]);
        // Pesée faite après la radio : pas celle-là (celle du setUp, il y a 3 jours, non plus)
        $cliche = $this->envoyer($this->dicom())->assertCreated()->json('data');

        $this->assertEquals(29.0, $cliche['poids_kg']);
    }

    /** Case 6 : la date d'une confirmation n'est pas dans le futur */
    public function test_une_confirmation_n_est_pas_datee_dans_le_futur(): void
    {
        $this->postJson($this->base().'/confirmations', ['etiquette' => 'fracture', 'resultat' => 'confirme', 'source' => 'chirurgie', 'constate_le' => now()->addDays(3)->toDateString()], $this->en())
            ->assertUnprocessable()->assertJsonValidationErrors('constate_le');
    }

    /** D9 : un faux fichier en syntaxe grand-boutiste ou dégonflée (illisible) est refusé, pas pris à l'aveugle */
    public function test_un_fichier_en_syntaxe_illisible_est_refuse(): void
    {
        $this->envoyer($this->dicom([], '1.2.840.10008.1.2.2'))->assertUnprocessable()->assertJsonValidationErrors('fichier');
        $this->envoyer($this->dicom([], '1.2.840.10008.1.2.1.99'))->assertUnprocessable();
    }

    // ————— Vérification, deuxième passe —————

    /** Case 8 : les dessins d'une radio se lisent aussi depuis une clinique sœur */
    public function test_une_clinique_soeur_lit_les_dessins(): void
    {
        $soeur = Clinique::factory()->create(['societe_id' => $this->clinique->societe_id]);
        ['jeton' => $jetonSoeur] = $this->employeAvecJeton($soeur);
        $cliche = $this->cliche();
        $this->postJson($this->base()."/cliches/{$cliche->id}/annotations", ['type' => 'point', 'points' => [[1, 1]], 'etiquette' => 'fracture', 'certitude' => 'sur'], $this->en())->assertCreated();

        $this->getJson($this->base()."/cliches/{$cliche->id}/annotations", $this->enTetes($jetonSoeur))->assertOk()->assertJsonCount(1, 'data');
    }

    /** Case 6 : la confirmation dit quel PDF la prouve */
    public function test_la_confirmation_dit_quel_document_la_prouve(): void
    {
        $document = Document::factory()->create(['patient_id' => $this->patient->id, 'nom' => 'Histologie Vetlab']);
        $this->postJson($this->base().'/confirmations', ['etiquette' => 'fracture', 'resultat' => 'confirme', 'source' => 'histologie', 'constate_le' => now()->toDateString(), 'document_id' => $document->id], $this->en())
            ->assertCreated()->assertJsonPath('data.document.nom', 'Histologie Vetlab');
        $this->getJson($this->base().'/confirmations', $this->en())->assertJsonPath('data.0.document.nom', 'Histologie Vetlab');
    }

    /** Un fichier coupé au milieu de ses pixels est refusé : la visionneuse ne pourrait pas l'afficher */
    public function test_un_fichier_coupe_dans_ses_pixels_est_refuse(): void
    {
        $octets = $this->dicom(['lignes' => 64, 'colonnes' => 64]);

        $this->envoyer(substr($octets, 0, -4000))->assertUnprocessable()->assertJsonValidationErrors('fichier');
    }

    /** Pixels compressés (en fragments) : gardés seulement s'ils vont jusqu'à leur délimiteur ; des pixels vides, jamais */
    public function test_des_pixels_compresses_coupes_ou_vides_sont_refuses(): void
    {
        $jpeg = '1.2.840.10008.1.2.4.50';
        $fragments = fn (string $donnees): string => pack('vv', 0x7FE0, 0x0010).'OB'."\0\0".pack('V', 0xFFFFFFFF)
            .pack('vvV', 0xFFFE, 0xE000, 0)
            .pack('vvV', 0xFFFE, 0xE000, strlen($donnees)).$donnees
            .pack('vvV', 0xFFFE, 0xE0DD, 0);
        $entier = $this->dicom(['sop' => '1.2.3.41'], $jpeg, avecPixels: false).$fragments(str_repeat("\xFF\xD8", 3000));

        $this->envoyer($entier)->assertCreated();
        // Coupé au milieu du fragment, ou juste avant le délimiteur
        $coupe = $this->dicom(['sop' => '1.2.3.42'], $jpeg, avecPixels: false).$fragments(str_repeat("\xFF\xD8", 3000));
        $this->envoyer(substr($coupe, 0, -2000))->assertUnprocessable()->assertJsonValidationErrors('fichier');
        $this->envoyer(substr($coupe, 0, -8))->assertUnprocessable()->assertJsonValidationErrors('fichier');
        // Des pixels de longueur nulle : rien à voir
        $vide = $this->dicom(['sop' => '1.2.3.43'], avecPixels: false).pack('vv', 0x7FE0, 0x0010).'OW'."\0\0".pack('V', 0);
        $this->envoyer($vide)->assertUnprocessable()->assertJsonValidationErrors('fichier');
    }

    /** D7, suite : par le patient d'une autre organisation, ni la liste des radios, ni les dessins, ni les repères, ni un nouveau dessin */
    public function test_le_chemin_d7_ne_lit_ni_la_liste_ni_les_dessins(): void
    {
        $autre = Clinique::factory()->create();
        ['jeton' => $jetonAutre] = $this->employeAvecJeton($autre);
        $patientAutre = Patient::factory()->for(Client::factory()->create(['organisation_id' => $autre->societe->organisation_id]))->create();
        $cliche = $this->cliche();
        $this->postJson("{$this->base()}/cliches/{$cliche->id}/annotations", ['type' => 'point', 'points' => [[2, 3]], 'etiquette' => 'fracture', 'certitude' => 'sur'], $this->en())->assertCreated();
        $chemin = "/api/v1/patients/{$patientAutre->id}/imagerie/{$this->examen->id}";

        $this->getJson("{$chemin}/cliches", $this->enTetes($jetonAutre))->assertNotFound();
        $this->getJson("{$chemin}/cliches/{$cliche->id}/annotations", $this->enTetes($jetonAutre))->assertNotFound();
        $this->patchJson("{$chemin}/cliches/{$cliche->id}", ['region' => 'thorax', 'incidence' => 'lateral_droit'], $this->enTetes($jetonAutre))->assertNotFound();
        $this->postJson("{$chemin}/cliches/{$cliche->id}/annotations", ['type' => 'point', 'points' => [[2, 3]], 'etiquette' => 'fracture', 'certitude' => 'sur'], $this->enTetes($jetonAutre))->assertNotFound();
        $this->assertSame(1, $cliche->annotations()->count());
    }

    /** Une syntaxe illisible (dégonflée, grand-boutiste) dit pourquoi, au lieu de « pas une image DICOM » */
    public function test_une_syntaxe_illisible_dit_pourquoi(): void
    {
        $this->envoyer($this->dicom([], '1.2.840.10008.1.2.1.99'))->assertUnprocessable()
            ->assertJsonValidationErrors(['fichier' => 'Ce format DICOM (compressé en flux ou grand-boutiste) n’est pas pris en charge : exportez la radio en DICOM standard depuis l’appareil.']);
    }

    /** Messages en français, même pour un champ vide ou trop long */
    public function test_les_messages_de_la_confirmation_sont_en_francais(): void
    {
        $this->postJson($this->base().'/confirmations', ['etiquette' => 'fracture', 'resultat' => 'confirme', 'source' => 'chirurgie', 'note' => str_repeat('x', 2001)], $this->en())
            ->assertUnprocessable()->assertJsonValidationErrors(['constate_le' => 'Indiquez la date.', 'note' => 'La note dépasse 2000 caractères.']);
    }

    /** Le jour de la radio se lit à l'heure de la clinique : 00 h 30 en Suisse, c'est encore ce jour-là */
    public function test_le_poids_du_jour_se_lit_a_l_heure_de_la_clinique(): void
    {
        $this->clinique->update(['fuseau_horaire' => 'Europe/Zurich']);
        // Comme l'app : l'instant en UTC (23 h 30 le 4 novembre, soit 00 h 30 le 5 en Suisse)
        $this->examen->update(['realise_le' => CarbonImmutable::parse('2026-11-04 23:30', 'UTC')]);
        Pesee::query()->delete();
        Pesee::factory()->create(['patient_id' => $this->patient->id, 'poids_kg' => 27.5, 'pesee_le' => '2026-11-05']);

        $this->assertEquals(27.5, $this->envoyer($this->dicom())->assertCreated()->json('data.poids_kg'));
    }
}
