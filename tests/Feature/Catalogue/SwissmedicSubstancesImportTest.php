<?php

namespace Tests\Feature\Catalogue;

use App\Mail\CatalogueImportAlert;
use App\Models\CatalogueImport;
use App\Models\ProduitVeterinaire;
use App\Services\Catalogue\ImportSwissmedic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Les principes actifs du catalogue Swissmedic (03.11.2026), après la
 * vérification indépendante : lus dans un extrait du format réel (lignes
 * reprises telles quelles de l'archive OGD du 31.10.2026 : Metacam et
 * Synulox), numéros d'autorisation comparés sans les zéros en tête ; les
 * deux fichiers sont facultatifs (absents ou illisibles : le reste
 * s'importe, les substances connues restent, un avertissement est écrit)
 * ; un garde-fou ne les efface jamais toutes ; une alerte par e-mail,
 * une fois par jour, quand l'import échoue ou n'a que cet avertissement.
 */
class SwissmedicSubstancesImportTest extends TestCase
{
    use RefreshDatabase;

    private const NS = 'xmlns:n0="http://ccsap.bit.admin.ch/SWISSMEDIC/LIMS_HMIT_KHZ"';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/swissmedic-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tmp);
        config(['catalogue.notification_email' => 'catalogue@example.test']);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    /** Les fichiers exigés, minimaux ; les produits viennent de l'extrait réel */
    private function base(): array
    {
        $ns = self::NS;
        $reel = base_path('tests/Fixtures/catalogue/swissmedic-reel');

        return [
            'OGD-Praeparate.XML' => (string) file_get_contents($reel.'/OGD-Praeparate.XML'),
            'OGD-Packungen.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Packung {$ns}></n0:SMC_Packung>",
            'OGD-Sequenzen.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Sequenz {$ns}></n0:SMC_Sequenz>",
            'OGD-Adressen.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Adressen {$ns}></n0:SMC_Adressen>",
            'OGD-Applikationsarten pro Sequenz.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Applikationsart {$ns}></n0:SMC_Applikationsart>",
            'OGD-ATC-Codes.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Atc {$ns}></n0:SMC_Atc>",
            'OGD-Export-Datum.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Datum {$ns}><DATUM><EXPORT_DATUM>2026-10-31</EXPORT_DATUM></DATUM></n0:SMC_Datum>",
            'OGD-Deklarationen.XML' => (string) file_get_contents($reel.'/OGD-Deklarationen.XML'),
            'OGD-Stoff-Synonyme.XML' => (string) file_get_contents($reel.'/OGD-Stoff-Synonyme.XML'),
        ];
    }

    private function archive(array $files): string
    {
        $path = $this->tmp.'/ogd-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function import(array $files, bool $force = true): CatalogueImport
    {
        return app(ImportSwissmedic::class)->importer($this->archive($files), $force);
    }

    private function substancesOf(string $number): ?array
    {
        return ProduitVeterinaire::query()->where('numero_autorisation', $number)->value('substances') !== null
            ? ProduitVeterinaire::query()->where('numero_autorisation', $number)->firstOrFail()->substances
            : null;
    }

    public function test_le_format_reel_donne_les_principes_actifs(): void
    {
        $journal = $this->import($this->base());

        $this->assertSame(CatalogueImport::REUSSI, $journal->statut);
        $this->assertSame(['meloxicamum'], $this->substancesOf('52802'));
        $this->assertEqualsCanonicalizing(['amoxicillinum anhydricum', 'acidum clavulanicum'], $this->substancesOf('46943'));
        Mail::assertNothingSent();
    }

    public function test_les_numeros_se_comparent_sans_les_zeros_en_tete(): void
    {
        $files = $this->base();
        $files['OGD-Deklarationen.XML'] = str_replace('<ZULASSUNGSNUMMER>52802</ZULASSUNGSNUMMER>', '<ZULASSUNGSNUMMER>052802</ZULASSUNGSNUMMER>', $files['OGD-Deklarationen.XML']);
        $files['OGD-Praeparate.XML'] = str_replace('<ZULASSUNGSNUMMER>46943</ZULASSUNGSNUMMER>', '<ZULASSUNGSNUMMER>0046943</ZULASSUNGSNUMMER>', $files['OGD-Praeparate.XML']);

        $this->import($files);

        $this->assertSame(['meloxicamum'], $this->substancesOf('52802'));
        $this->assertNotEmpty($this->substancesOf('0046943'));
    }

    public function test_le_rang_des_synonymes_se_compare_en_nombre(): void
    {
        $files = $this->base();
        $id = '001F295B11971ED2B6E5D2DAFA567F9F';
        $files['OGD-Stoff-Synonyme.XML'] = str_replace('</n0:SMC_Synonyme>', "<SYNONYME><STOFF_ID>{$id}</STOFF_ID><SYNONYM_CODE>LN</SYNONYM_CODE><LAUFENDE_NR>10</LAUFENDE_NR><STOFFSYNONYM>nomen decimum</STOFFSYNONYM><QUELLE/></SYNONYME></n0:SMC_Synonyme>", $files['OGD-Stoff-Synonyme.XML']);
        $files['OGD-Stoff-Synonyme.XML'] = str_replace('<LAUFENDE_NR>001</LAUFENDE_NR><STOFFSYNONYM>meloxicamum</STOFFSYNONYM>', '<LAUFENDE_NR>9</LAUFENDE_NR><STOFFSYNONYM>meloxicamum</STOFFSYNONYM>', $files['OGD-Stoff-Synonyme.XML']);

        $this->import($files);

        $this->assertSame(['meloxicamum'], $this->substancesOf('52802'));
    }

    public function test_sans_les_deux_fichiers_le_reste_s_importe_et_les_substances_restent(): void
    {
        $this->import($this->base());
        $files = $this->base();
        unset($files['OGD-Deklarationen.XML'], $files['OGD-Stoff-Synonyme.XML']);
        $files['OGD-Praeparate.XML'] = str_replace('orale Suspension für Hunde', 'orale Suspension für Hunde und Katzen', $files['OGD-Praeparate.XML']);

        $journal = $this->import($files);

        $this->assertSame(CatalogueImport::REUSSI, $journal->statut);
        $this->assertStringContainsString('Avertissement', (string) $journal->message);
        $this->assertStringContainsString('und Katzen', ProduitVeterinaire::query()->where('numero_autorisation', '52802')->value('nom'));
        $this->assertSame(['meloxicamum'], $this->substancesOf('52802'));
        Mail::assertSent(CatalogueImportAlert::class, fn (CatalogueImportAlert $mail) => $mail->hasTo('catalogue@example.test'));
    }

    public function test_un_fichier_illisible_est_un_avertissement(): void
    {
        $this->import($this->base());
        $files = $this->base();
        $files['OGD-Deklarationen.XML'] = '<?xml version="1.0"?><n0:SMC_Deklaration '.self::NS.'><DEKLARATION><ZULASSUNGSNUMMER>52802';

        $journal = $this->import($files);

        $this->assertSame(CatalogueImport::REUSSI, $journal->statut);
        $this->assertStringContainsString('Avertissement', (string) $journal->message);
        $this->assertSame(['meloxicamum'], $this->substancesOf('52802'));
    }

    public function test_le_garde_fou_n_efface_jamais_toutes_les_substances(): void
    {
        $this->import($this->base());
        $files = $this->base();
        // Plus aucun principe actif déclaré (une colonne renommée, par exemple)
        $files['OGD-Deklarationen.XML'] = str_replace('<STOFFKATEGORIE>WIRKS</STOFFKATEGORIE>', '<STOFFKATEGORIE>AUTRE</STOFFKATEGORIE>', $files['OGD-Deklarationen.XML']);

        $journal = $this->import($files);

        $this->assertStringContainsString('Avertissement', (string) $journal->message);
        $this->assertSame(['meloxicamum'], $this->substancesOf('52802'));
        Mail::assertSent(CatalogueImportAlert::class);
    }

    public function test_un_echec_envoie_une_alerte_une_seule_fois_par_jour(): void
    {
        $files = $this->base();
        unset($files['OGD-Packungen.XML']);

        foreach ([1, 2] as $attempt) {
            try {
                $this->import($files);
                $this->fail('L import aurait dû échouer');
            } catch (RuntimeException) {
                // attendu
            }
        }

        $this->assertSame(2, CatalogueImport::query()->where('statut', CatalogueImport::ECHEC)->count());
        Mail::assertSent(CatalogueImportAlert::class, 1);
    }
}
