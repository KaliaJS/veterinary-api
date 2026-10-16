<?php

namespace Tests\Feature\Catalogue;

use App\Models\CatalogueImport;
use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use App\Services\Catalogue\ImportRefdata;
use App\Services\Catalogue\ImportSwissmedic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * Import du catalogue depuis des archives miniatures bâties ici (mêmes
 * balises que les flux Swissmedic OGD et Refdata) : produits vétérinaires
 * seulement, valences déduites de l ATCvet ou curées, schéma curé,
 * emballages et GTIN, retrait d un produit disparu, fichier identique
 * ignoré, archive suspecte refusée ; téléchargements simulés : ETag
 * mémorisé puis renvoyé (304 : rien téléchargé), clé Refdata en en-tête,
 * aucune archive gardée.
 */
class ImportCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_import_swissmedic_cree_les_vaccins_avec_valences_schema_et_emballages(): void
    {
        $journal = app(ImportSwissmedic::class)->importer($this->archiveSwissmedic());

        $this->assertSame(CatalogueImport::REUSSI, $journal->statut);
        $this->assertSame(2, $journal->ajoutes);
        $this->assertSame('2026-09-30', $journal->export_le->toDateString());
        $this->assertSame(0, ProduitVeterinaire::query()->where('nom', 'like', 'Dafalgan%')->count(), 'les médicaments humains ne sont pas importés');

        $dhppi = ProduitVeterinaire::query()->where('numero_autorisation', '1631')->with(['valences', 'schema', 'emballages'])->firstOrFail();
        $this->assertSame('Nobivac DHPPi', $dhppi->nom_court);
        $this->assertSame(ProduitVeterinaire::VACCIN, $dhppi->categorie);
        $this->assertSame(['CH'], $dhppi->especes);
        $this->assertSame('MSD Animal Health GmbH', $dhppi->titulaire);
        $this->assertEqualsCanonicalizing(['carre', 'hepatite', 'parvovirose', 'parainfluenza'], $dhppi->valences->pluck('code')->all());
        $this->assertSame(2, $dhppi->schema->primo_injections);
        $this->assertSame(12, $dhppi->schema->rappel_mois);
        $this->assertSame(['SUBC'], $dhppi->voies);
        $this->assertSame('01631001', $dhppi->emballages->first()->numero_emballage);

        $rage = ProduitVeterinaire::query()->where('numero_autorisation', '1337')->with('valences')->firstOrFail();
        $this->assertEqualsCanonicalizing(['CH', 'CT', 'CV', 'BV', 'OV', 'CP', 'FU'], $rage->especes);
        $this->assertSame(['rage'], $rage->valences->pluck('code')->all());
    }

    public function test_un_fichier_identique_est_ignore_et_un_produit_disparu_est_retire(): void
    {
        $import = app(ImportSwissmedic::class);
        $import->importer($this->archiveSwissmedic());
        $this->assertSame(CatalogueImport::IDENTIQUE, $import->importer($this->archiveSwissmedic())->statut);

        $journal = $import->importer($this->archiveSwissmedic(sansRage: true));
        $this->assertSame(1, $journal->retires);
        $this->assertNotNull(ProduitVeterinaire::query()->where('numero_autorisation', '1337')->value('retire_le'));
        $this->assertNull(ProduitVeterinaire::query()->where('numero_autorisation', '1631')->value('retire_le'));
    }

    public function test_une_archive_avec_une_entree_inattendue_est_refusee(): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'ogd').'.zip';
        $zip = new ZipArchive;
        $zip->open($chemin, ZipArchive::CREATE);
        $zip->addFromString('OGD-Praeparate.XML', '<x/>');
        $zip->addFromString('vbaProject.bin', 'x');
        $zip->close();

        $this->expectExceptionMessage('Entrée inattendue');
        try {
            app(ImportSwissmedic::class)->importer($chemin);
        } finally {
            $this->assertSame(CatalogueImport::ECHEC, CatalogueImport::query()->latest('id')->value('statut'));
        }
    }

    public function test_l_import_refdata_pose_les_gtin_sur_les_emballages_connus(): void
    {
        app(ImportSwissmedic::class)->importer($this->archiveSwissmedic());
        $journal = app(ImportRefdata::class)->importer($this->archiveRefdata());

        $this->assertSame(CatalogueImport::REUSSI, $journal->statut);
        $this->assertSame(1, $journal->modifies);
        $emballage = ProduitEmballage::query()->where('numero_emballage', '01631001')->firstOrFail();
        $this->assertSame('8713184095282', $emballage->gtin);
        $this->assertSame('NOBIVAC DHPPi ad us. vet. susp pdr', $emballage->nom_fr);
        $this->assertStringContainsString('1 sans correspondance', $journal->message);
    }

    public function test_le_telechargement_swissmedic_memorise_l_etag_et_ne_garde_pas_l_archive(): void
    {
        Http::fake([config('catalogue.swissmedic.url') => Http::response(file_get_contents($this->archiveSwissmedic()), 200, ['ETag' => '"v1"'])]);

        $journal = app(ImportSwissmedic::class)->importer();

        $this->assertSame(CatalogueImport::REUSSI, $journal->statut);
        $this->assertSame(2, $journal->ajoutes);
        $this->assertSame('"v1"', $journal->fichier_etag);
        Http::assertSent(fn (Request $r): bool => $r->header('Cache-Control') === ['no-cache'] && ! $r->hasHeader('If-None-Match'));
        $this->assertFileDoesNotExist(storage_path('app/catalogue/swissmedic/OGD.zip'));
    }

    public function test_swissmedic_ne_telecharge_rien_quand_le_serveur_repond_inchange(): void
    {
        CatalogueImport::query()->create(['source' => CatalogueImport::SWISSMEDIC, 'statut' => CatalogueImport::REUSSI, 'fichier_hash' => str_repeat('a', 64), 'fichier_etag' => '"v1"']);
        Http::fake([config('catalogue.swissmedic.url') => Http::response('', 304)]);

        $journal = app(ImportSwissmedic::class)->importer();

        $this->assertSame(CatalogueImport::IDENTIQUE, $journal->statut);
        $this->assertSame('"v1"', $journal->fichier_etag);
        $this->assertSame(str_repeat('a', 64), $journal->fichier_hash);
        Http::assertSent(fn (Request $r): bool => $r->header('If-None-Match') === ['"v1"']);
        $this->assertFileDoesNotExist(storage_path('app/catalogue/swissmedic/OGD.zip'));
    }

    public function test_refdata_envoie_la_cle_en_en_tete_et_ne_garde_pas_l_archive(): void
    {
        config(['catalogue.refdata.cle' => 'cle-de-test']);
        Http::fake([config('catalogue.refdata.url') => Http::response(file_get_contents($this->archiveRefdata()))]);
        app(ImportSwissmedic::class)->importer($this->archiveSwissmedic());

        $journal = app(ImportRefdata::class)->importer();

        $this->assertSame(CatalogueImport::REUSSI, $journal->statut);
        $this->assertSame(1, $journal->modifies);
        Http::assertSent(fn (Request $r): bool => $r->header('X-API-Key') === ['cle-de-test'] && $r->header('Cache-Control') === ['no-cache'] && ! str_contains($r->url(), 'cle-de-test'));
        $this->assertFileDoesNotExist(storage_path('app/catalogue/refdata/Refdata.Articles.zip'));
    }

    private function archiveSwissmedic(bool $sansRage = false): string
    {
        $ns = 'xmlns:n0="http://ccsap.bit.admin.ch/SWISSMEDIC/LIMS_HMIT_KHZ"';
        $rage = $sansRage ? '' : '<PRAEPARAT><VERWENDUNG>TAM</VERWENDUNG><ZULASSUNGSNUMMER>1337</ZULASSUNGSNUMMER><PRAEPARATENAME>Nobivac Rabies ad us. vet., Injektionssuspension für Hunde, Katzen, Pferde, Rinder, Schafe, Ziegen und Frettchen</PRAEPARATENAME><ARZNEIFORM>SUSINJ</ARZNEIFORM><ATC_CODE>QI07AA02</ATC_CODE><HEILMITTEL_CODE>I</HEILMITTEL_CODE><ZULASSUNGSSTATUS>Z</ZULASSUNGSSTATUS><ZULASSUNGSINHABERIN>1006206</ZULASSUNGSINHABERIN><ERSTZULASSUNGSDATUM>1990-01-01</ERSTZULASSUNGSDATUM><ABGABEKATEGORIE>B</ABGABEKATEGORIE><ANWENDUNGSGEBIET>Tollwut</ANWENDUNGSGEBIET><ABLAUFDATUM>9999-12-31</ABLAUFDATUM><CHARGENFREIGABE_PFLICHT>X</CHARGENFREIGABE_PFLICHT></PRAEPARAT>';
        $fichiers = [
            'OGD-Praeparate.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Praeparat {$ns}>"
                .'<PRAEPARAT><VERWENDUNG>HAM</VERWENDUNG><ZULASSUNGSNUMMER>50000</ZULASSUNGSNUMMER><PRAEPARATENAME>Dafalgan</PRAEPARATENAME><ATC_CODE>N02BE01</ATC_CODE><HEILMITTEL_CODE>S</HEILMITTEL_CODE><ZULASSUNGSSTATUS>Z</ZULASSUNGSSTATUS><ZULASSUNGSINHABERIN>1000001</ZULASSUNGSINHABERIN></PRAEPARAT>'
                .'<PRAEPARAT><VERWENDUNG>TAM</VERWENDUNG><ZULASSUNGSNUMMER>1631</ZULASSUNGSNUMMER><PRAEPARATENAME>Nobivac DHPPi ad us. vet., Lyophilisat zur Herstellung einer Injektionssuspension für Hunde</PRAEPARATENAME><ARZNEIFORM>LYO</ARZNEIFORM><ATC_CODE>QI07AD04</ATC_CODE><HEILMITTEL_CODE>I</HEILMITTEL_CODE><ZULASSUNGSSTATUS>Z</ZULASSUNGSSTATUS><ZULASSUNGSINHABERIN>1006206</ZULASSUNGSINHABERIN><ERSTZULASSUNGSDATUM>1998-05-20</ERSTZULASSUNGSDATUM><ABGABEKATEGORIE>B</ABGABEKATEGORIE><ANWENDUNGSGEBIET>Staupe</ANWENDUNGSGEBIET><ABLAUFDATUM>9999-12-31</ABLAUFDATUM><CHARGENFREIGABE_PFLICHT>X</CHARGENFREIGABE_PFLICHT></PRAEPARAT>'
                .$rage.'</n0:SMC_Praeparat>',
            'OGD-Packungen.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Packung {$ns}>"
                .'<PACKUNG><ZULASSUNGSNUMMER>1631</ZULASSUNGSNUMMER><SEQUENZNUMMER>1</SEQUENZNUMMER><PACKUNGSCODE>1</PACKUNGSCODE><ZULASSUNGSSTATUS>Z</ZULASSUNGSSTATUS><BEMERKUNG_FREITEXT>Dosis Lyophilisat</BEMERKUNG_FREITEXT><PACKUNGSGROESSE>10 x 1</PACKUNGSGROESSE><PACKUNGSEINHEIT>LEER</PACKUNGSEINHEIT><WIDERRUF_VERZICHT_DATUM></WIDERRUF_VERZICHT_DATUM></PACKUNG>'
                .'<PACKUNG><ZULASSUNGSNUMMER>1337</ZULASSUNGSNUMMER><SEQUENZNUMMER>1</SEQUENZNUMMER><PACKUNGSCODE>1</PACKUNGSCODE><ZULASSUNGSSTATUS>Z</ZULASSUNGSSTATUS><PACKUNGSGROESSE>10</PACKUNGSGROESSE><PACKUNGSEINHEIT>LEER</PACKUNGSEINHEIT><WIDERRUF_VERZICHT_DATUM></WIDERRUF_VERZICHT_DATUM></PACKUNG></n0:SMC_Packung>',
            'OGD-Sequenzen.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Sequenz {$ns}><SEQUENZ><ZULASSUNGSNUMMER>1631</ZULASSUNGSNUMMER><SEQUENZNUMMER>1</SEQUENZNUMMER><WIDERRUF_VERZICHT_DATUM></WIDERRUF_VERZICHT_DATUM></SEQUENZ></n0:SMC_Sequenz>",
            'OGD-Adressen.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Adressen {$ns}><ADRESSEN><PARTNER_NR>1006206</PARTNER_NR><FIRMENNAME>MSD Animal Health GmbH</FIRMENNAME></ADRESSEN></n0:SMC_Adressen>",
            'OGD-Applikationsarten pro Sequenz.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Applikationsart {$ns}><APPLIKATIONSART><ZULASSUNGSNUMMER>1631</ZULASSUNGSNUMMER><SEQUENZNUMMER>1</SEQUENZNUMMER><APPLIKATIONSART_CODE>SUBC</APPLIKATIONSART_CODE></APPLIKATIONSART></n0:SMC_Applikationsart>",
            'OGD-ATC-Codes.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Atc {$ns}><ATC><ATC_CODE>QI07AD04</ATC_CODE><ATC_INDIKATIONS_BESCHREIBUNG>CANINE DISTEMPER VIRUS + CANINE ADENOVIRUS + CANINE PARVOVIRUS + CANINE PARAINFLUENZA VIRUS</ATC_INDIKATIONS_BESCHREIBUNG></ATC><ATC><ATC_CODE>QI07AA02</ATC_CODE><ATC_INDIKATIONS_BESCHREIBUNG>RABIES VIRUS</ATC_INDIKATIONS_BESCHREIBUNG></ATC></n0:SMC_Atc>",
            'OGD-Export-Datum.XML' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><n0:SMC_Datum {$ns}><DATUM><EXPORT_DATUM>2026-09-30</EXPORT_DATUM></DATUM></n0:SMC_Datum>",
        ];

        return $this->archive('ogd', $fichiers);
    }

    private function archiveRefdata(): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><Articles xmlns="https://simisinfo.refdata.ch/Articles/2.0/">'
            .'<Article><MedicinalProduct><Identifier>CH-1-1</Identifier><Domain>Human</Domain></MedicinalProduct><PackagedProduct><RegulatedAuthorisationIdentifier>50000001</RegulatedAuthorisationIdentifier><DataCarrierIdentifier>7680500000011</DataCarrierIdentifier><MarketingStatus><DateStart>2000-01-01</DateStart></MarketingStatus></PackagedProduct></Article>'
            .'<Article><MedicinalProduct><Identifier>CH-7601001053854-0163101</Identifier><Domain>Veterinary</Domain><RegulatedAuthorisationIdentifier>0163101</RegulatedAuthorisationIdentifier><ProductClassification><Atc>QI07AD04</Atc></ProductClassification></MedicinalProduct>'
            .'<PackagedProduct><Identifier>CH-7601001053854-0163101-001</Identifier><RegulatedAuthorisationIdentifier>01631001</RegulatedAuthorisationIdentifier><DataCarrierIdentifier>8713184095282</DataCarrierIdentifier><Holder><Name>MSD Animal Health GmbH</Name></Holder><Name><Language>DE</Language><FullName>NOBIVAC DHPPi ad us. vet. Trockensub</FullName></Name><Name><Language>FR</Language><FullName>NOBIVAC DHPPi ad us. vet. susp pdr</FullName></Name><MarketingStatus><DateStart>2006-05-01</DateStart></MarketingStatus></PackagedProduct></Article>'
            .'<Article><MedicinalProduct><Identifier>CH-9-9</Identifier><Domain>Veterinary</Domain></MedicinalProduct><PackagedProduct><RegulatedAuthorisationIdentifier>09999001</RegulatedAuthorisationIdentifier><DataCarrierIdentifier>7680999990011</DataCarrierIdentifier><MarketingStatus><DateStart>2000-01-01</DateStart></MarketingStatus></PackagedProduct></Article>'
            .'</Articles>';

        return $this->archive('refdata', ['Refdata.Articles.xml' => $xml]);
    }

    /** @param  array<string, string>  $fichiers */
    private function archive(string $prefixe, array $fichiers): string
    {
        $chemin = tempnam(sys_get_temp_dir(), $prefixe).'.zip';
        $zip = new ZipArchive;
        $zip->open($chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($fichiers as $nom => $contenu) {
            $zip->addFromString($nom, $contenu);
        }
        $zip->close();

        return $chemin;
    }
}
