<?php

namespace App\Services\Catalogue;

use App\Mail\CatalogueImportAlert;
use App\Mail\NouveauxProduitsCatalogue;
use App\Models\CatalogueImport;
use App\Models\ProduitEmballage;
use App\Models\ProduitVeterinaire;
use App\Models\Vaccination;
use App\Models\VaccinSchema;
use App\Models\Valence;
use App\Support\MasquageSql;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Import mensuel du flux OGD de Swissmedic (ZIP d XML) : les médicaments
 * vétérinaires (VERWENDUNG = TAM), leurs emballages, voies et titulaires.
 * Idempotent : clé = numéro d autorisation ; un produit disparu est marqué
 * retiré, jamais supprimé ; les données curées (valences, schémas) sont
 * posées à part et jamais écrasées par le flux. Tout dans une transaction :
 * un fichier illisible ou aberrant ne modifie rien. Journal dans
 * `catalogue_imports` ; nouveaux vaccins notifiés.
 */
final class ImportSwissmedic
{
    private const FICHIERS = ['OGD-Praeparate.XML', 'OGD-Packungen.XML', 'OGD-Sequenzen.XML', 'OGD-Adressen.XML',
        'OGD-Applikationsarten pro Sequenz.XML', 'OGD-ATC-Codes.XML', 'OGD-Export-Datum.XML'];

    /** Les principes actifs et leur nom latin (03.11.2026) : facultatifs, leur absence n'arrête pas l'import */
    private const SUBSTANCE_FILES = ['OGD-Deklarationen.XML', 'OGD-Stoff-Synonyme.XML'];

    /**
     * Avertissements de ce passage (fichiers des substances absents, illisibles, ou vides)
     *
     * @var list<string>
     */
    private array $warnings = [];

    /** Importe depuis l URL configurée (ou un fichier local) ; retourne le journal. */
    public function importer(?string $fichierLocal = null, bool $forcer = false): CatalogueImport
    {
        $debut = hrtime(true);
        $journal = new CatalogueImport(['source' => CatalogueImport::SWISSMEDIC, 'statut' => CatalogueImport::ECHEC]);
        $dossier = storage_path('app/catalogue/swissmedic');
        // Dernier passage concluant (importé ou identique) : son empreinte et l ETag du serveur
        $precedent = CatalogueImport::query()
            ->where('source', CatalogueImport::SWISSMEDIC)
            ->whereIn('statut', [CatalogueImport::REUSSI, CatalogueImport::IDENTIQUE])
            ->latest('id')->first();
        try {
            if ($fichierLocal === null) {
                [$archive, $journal->fichier_etag] = $this->telecharger($dossier, $forcer ? null : $precedent?->fichier_etag);
                if ($archive === null) {
                    $journal->fichier_hash = $precedent?->fichier_hash;
                    $journal->statut = CatalogueImport::IDENTIQUE;
                    $journal->message = 'Fichier inchangé sur le serveur, rien téléchargé';

                    return $this->clore($journal, $debut);
                }
            } else {
                $archive = $fichierLocal;
            }
            $journal->fichier_hash = hash_file('sha256', $archive);
            if (! $forcer && $precedent && $precedent->fichier_hash === $journal->fichier_hash) {
                $journal->statut = CatalogueImport::IDENTIQUE;
                $journal->message = 'Fichier identique au dernier import';

                return $this->clore($journal, $debut);
            }
            $this->warnings = [];
            $chemins = LecteurXml::extraire($archive, $dossier.'/xml', self::FICHIERS, config('catalogue.swissmedic.taille_max_entree'));
            $chemins += $this->extractSubstanceFiles($archive, $dossier.'/xml');
            $journal->export_le = $this->dateExport($chemins['OGD-Export-Datum.XML']);
            $resultat = DB::transaction(fn (): array => $this->integrer($chemins, $journal->export_le));
            $journal->fill($resultat)->statut = CatalogueImport::REUSSI;
            $journal->message = $this->warningMessage();
            $this->clore($journal, $debut);
            $this->notifier($resultat['nouveaux_vaccins']);
            if ($journal->message !== null) {
                $this->alert($journal);
            }
        } catch (Throwable $erreur) {
            $journal->message = mb_substr(MasquageSql::message($erreur->getMessage()), 0, 2000);
            $this->clore($journal, $debut);
            $this->alert($journal);
            throw $erreur;
        } finally {
            // Rien n est conservé sur le disque : ni les XML extraits ni l archive téléchargée
            File::deleteDirectory($dossier.'/xml');
            if ($fichierLocal === null) {
                File::delete($dossier.'/OGD.zip');
            }
        }

        return $journal;
    }

    /**
     * Télécharge l archive. Avec l ETag du dernier fichier importé, le serveur
     * répond « 304, inchangé » sans corps : rien n est transféré.
     *
     * @return array{0: ?string, 1: ?string} chemin de l archive (null : inchangée) et ETag du serveur
     */
    private function telecharger(string $dossier, ?string $etagConnu): array
    {
        File::ensureDirectoryExists($dossier);
        $cible = $dossier.'/OGD.zip';
        $entetes = ['Cache-Control' => 'no-cache'];
        if ($etagConnu) {
            $entetes['If-None-Match'] = $etagConnu;
        }
        $reponse = Http::timeout(300)->withUserAgent('veterinary-api')->withHeaders($entetes)->sink($cible)
            ->get(config('catalogue.swissmedic.url'));
        if ($reponse->status() === 304) {
            return [null, $etagConnu];
        }
        if (! $reponse->successful()) {
            throw new RuntimeException('Téléchargement Swissmedic en échec : HTTP '.$reponse->status());
        }
        if (filesize($cible) > config('catalogue.swissmedic.taille_max')) {
            throw new RuntimeException('Archive Swissmedic plus grande qu attendu');
        }

        return [$cible, $reponse->header('ETag') ?: null];
    }

    private function dateExport(string $chemin): ?Carbon
    {
        foreach (LecteurXml::elements($chemin, 'DATUM') as $ligne) {
            return isset($ligne['EXPORT_DATUM']) ? Carbon::parse($ligne['EXPORT_DATUM']) : null;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $chemins
     * @return array{ajoutes: int, modifies: int, retires: int, nouveaux_vaccins: list<string>}
     */
    private function integrer(array $chemins, ?Carbon $exportLe): array
    {
        $this->poserValences();
        $titulaires = [];
        foreach (LecteurXml::elements($chemins['OGD-Adressen.XML'], 'ADRESSEN') as $adresse) {
            if (($adresse['PARTNER_NR'] ?? '') !== '') {
                $titulaires[$adresse['PARTNER_NR']] = mb_substr($adresse['FIRMENNAME'] ?? '', 0, 255);
            }
        }
        $atc = [];
        foreach (LecteurXml::elements($chemins['OGD-ATC-Codes.XML'], 'ATC') as $code) {
            $atc[$code['ATC_CODE']] = $code['ATC_INDIKATIONS_BESCHREIBUNG'] ?? '';
        }
        $voies = [];
        foreach (LecteurXml::elements($chemins['OGD-Applikationsarten pro Sequenz.XML'], 'APPLIKATIONSART') as $voie) {
            $voies[$voie['ZULASSUNGSNUMMER']][] = $voie['APPLIKATIONSART_CODE'];
        }
        $retraitsSequences = [];
        foreach (LecteurXml::elements($chemins['OGD-Sequenzen.XML'], 'SEQUENZ') as $sequence) {
            if (($sequence['WIDERRUF_VERZICHT_DATUM'] ?? '') !== '') {
                $retraitsSequences[$sequence['ZULASSUNGSNUMMER'].'|'.$sequence['SEQUENZNUMMER']] = $sequence['WIDERRUF_VERZICHT_DATUM'];
            }
        }

        $substances = $this->readSubstances($chemins);

        $ajoutes = 0;
        $modifies = 0;
        $nouveauxVaccins = [];
        $vus = [];
        $parNumero = ProduitVeterinaire::query()->get()->keyBy('numero_autorisation');
        foreach (LecteurXml::elements($chemins['OGD-Praeparate.XML'], 'PRAEPARAT') as $ligne) {
            if (($ligne['VERWENDUNG'] ?? '') !== 'TAM' || ($ligne['ZULASSUNGSNUMMER'] ?? '') === '') {
                continue;
            }
            $numero = $ligne['ZULASSUNGSNUMMER'];
            $vus[$numero] = true;
            $attributs = $this->attributs($ligne, $titulaires, $voies[$numero] ?? [], $exportLe);
            // Fichiers des substances absents ou suspects : celles déjà connues restent
            if ($substances !== null) {
                $attributs['substances'] = $substances[self::normalizeNumber($numero)] ?? null;
            }
            $produit = $parNumero->get($numero);
            if ($produit === null) {
                $produit = ProduitVeterinaire::create($attributs);
                $parNumero->put($numero, $produit);
                $ajoutes++;
                if ($produit->estVaccin()) {
                    $nouveauxVaccins[] = $produit->nom_court;
                }
            } else {
                // Rien n a changé : aucune écriture (la date de passage du
                // flux n est posée que sur les produits réellement modifiés)
                $produit->fill(array_diff_key($attributs, ['source_le' => true]));
                if ($produit->isDirty()) {
                    $produit->source_le = $exportLe;
                    $produit->save();
                    $modifies++;
                }
            }
            if ($produit->estVaccin()) {
                $this->poserValencesDuProduit($produit, $atc[$produit->atc_code] ?? '');
                $this->poserSchema($produit);
            }
        }

        $retires = 0;
        foreach ($parNumero as $numero => $produit) {
            if (! isset($vus[$numero]) && $produit->retire_le === null) {
                $produit->update(['retire_le' => $exportLe ?? aujourdhui()]);
                $retires++;
            }
        }

        $this->poserEmballages($chemins['OGD-Packungen.XML'], $parNumero, $retraitsSequences);

        return ['ajoutes' => $ajoutes, 'modifies' => $modifies, 'retires' => $retires, 'nouveaux_vaccins' => $nouveauxVaccins];
    }

    /** Les avertissements du passage, en une phrase pour le journal ; null s'il n'y en a pas */
    private function warningMessage(): ?string
    {
        return $this->warnings === [] ? null : mb_substr('Avertissement : '.implode(' ', $this->warnings), 0, 2000);
    }

    /** Un numéro d'autorisation sans ses zéros en tête (« 052802 » et « 52802 ») */
    public static function normalizeNumber(string $number): string
    {
        $trimmed = ltrim(trim($number), '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * Les deux fichiers des substances, s'ils sont dans l'archive ; sinon un
     * avertissement, et l'import continue.
     *
     * @return array<string, string>
     */
    private function extractSubstanceFiles(string $archive, string $folder): array
    {
        $paths = [];
        foreach (self::SUBSTANCE_FILES as $file) {
            try {
                $paths += LecteurXml::extraire($archive, $folder, [$file], config('catalogue.swissmedic.taille_max_entree'));
            } catch (Throwable $error) {
                $this->warnings[] = "{$file} absent ou illisible : les principes actifs déjà connus sont gardés.";
            }
        }

        return $paths;
    }

    /**
     * Les principes actifs par produit vétérinaire, ou null quand il ne faut
     * pas y toucher : fichier absent, illisible, ou plus aucun principe actif
     * alors que le catalogue en avait (garde-fou).
     *
     * @param  array<string, string>  $paths
     * @return array<string, list<string>>|null
     */
    private function readSubstances(array $paths): ?array
    {
        if (! isset($paths['OGD-Deklarationen.XML'], $paths['OGD-Stoff-Synonyme.XML'])) {
            return null;
        }
        try {
            $veterinary = [];
            foreach (LecteurXml::elements($paths['OGD-Praeparate.XML'], 'PRAEPARAT') as $line) {
                if (($line['VERWENDUNG'] ?? '') === 'TAM' && ($line['ZULASSUNGSNUMMER'] ?? '') !== '') {
                    $veterinary[self::normalizeNumber($line['ZULASSUNGSNUMMER'])] = true;
                }
            }
            $byProduct = $this->substances($paths['OGD-Deklarationen.XML'], $paths['OGD-Stoff-Synonyme.XML'], $veterinary);
        } catch (Throwable $error) {
            $this->warnings[] = 'Les fichiers des principes actifs sont illisibles : ceux déjà connus sont gardés.';

            return null;
        }
        if ($byProduct === [] && ProduitVeterinaire::query()->whereNotNull('substances')->exists()) {
            $this->warnings[] = 'Aucun produit vétérinaire n’a reçu de principe actif alors que le catalogue en avait : ceux déjà connus sont gardés.';

            return null;
        }

        return $byProduct;
    }

    /**
     * Les principes actifs (catégorie WIRKS de la déclaration) de chaque
     * produit vétérinaire, toutes séquences confondues, dans l'ordre de la
     * déclaration et sans doublon, nommés par leur premier synonyme latin
     * (LN, rang comparé en nombre). Un principe sans nom latin est laissé de
     * côté : rien n'est deviné. Seuls les produits vétérinaires sont gardés
     * en mémoire.
     *
     * @param  array<string, true>  $veterinary  numéros normalisés des produits vétérinaires
     * @return array<string, list<string>> numéro normalisé → noms
     */
    private function substances(string $declarations, string $synonyms, array $veterinary): array
    {
        $lines = [];
        $wanted = [];
        foreach (LecteurXml::elements($declarations, 'DEKLARATION') as $line) {
            $number = self::normalizeNumber($line['ZULASSUNGSNUMMER'] ?? '');
            $id = $line['STOFF_ID'] ?? '';
            if (($line['STOFFKATEGORIE'] ?? '') !== 'WIRKS' || $id === '' || ! isset($veterinary[$number])) {
                continue;
            }
            $lines[$number][] = [(int) ($line['SEQUENZNUMMER'] ?? 0), (int) ($line['SORTIERUNG_ZEILENNUMMER'] ?? 0), $id];
            $wanted[$id] = true;
        }
        $names = [];
        foreach (LecteurXml::elements($synonyms, 'SYNONYME') as $synonym) {
            $id = $synonym['STOFF_ID'] ?? '';
            $name = $synonym['STOFFSYNONYM'] ?? '';
            $rank = (int) ($synonym['LAUFENDE_NR'] ?? PHP_INT_MAX);
            if (! isset($wanted[$id]) || $name === '' || ($synonym['SYNONYM_CODE'] ?? '') !== 'LN') {
                continue;
            }
            if (! isset($names[$id]) || $rank < $names[$id][0]) {
                $names[$id] = [$rank, mb_substr($name, 0, 255)];
            }
        }
        $byProduct = [];
        foreach ($lines as $number => $list) {
            usort($list, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $found = array_values(array_unique(array_filter(array_map(fn (array $l): ?string => $names[$l[2]][1] ?? null, $list))));
            if ($found !== []) {
                $byProduct[(string) $number] = $found;
            }
        }

        return $byProduct;
    }

    /**
     * Une alerte par e-mail (échec, ou avertissement), une seule par jour et
     * par source, à l'adresse du catalogue ou à celle des alertes de la
     * plateforme ; un e-mail qui ne part pas n'arrête rien.
     */
    private function alert(CatalogueImport $journal): void
    {
        $to = config('catalogue.notification_email') ?: config('plateforme.alerte_email');
        if (! $to || ! $journal->exists) {
            return;
        }
        $alreadySent = CatalogueImport::query()
            ->where('source', $journal->source)
            ->whereNotNull('alert_sent_at')
            ->where('alert_sent_at', '>=', now()->startOfDay())
            ->exists();
        if ($alreadySent) {
            return;
        }
        try {
            Mail::to($to)->send(new CatalogueImportAlert($journal));
            $journal->forceFill(['alert_sent_at' => now()])->save();
        } catch (Throwable $error) {
            report($error);
        }
    }

    /**
     * @param  array<string, string>  $ligne
     * @param  array<string, string>  $titulaires
     * @param  list<string>  $voies
     * @return array<string, mixed>
     */
    private function attributs(array $ligne, array $titulaires, array $voies, ?Carbon $exportLe): array
    {
        $nom = $ligne['PRAEPARATENAME'] ?? '';
        $atc = $ligne['ATC_CODE'] ?? null;
        $categorie = match ($ligne['HEILMITTEL_CODE'] ?? '') {
            'I' => ProduitVeterinaire::VACCIN,
            'AI' => ProduitVeterinaire::IMMUNOLOGIQUE,
            default => str_starts_with((string) $atc, 'QI') ? ProduitVeterinaire::IMMUNOLOGIQUE : ProduitVeterinaire::MEDICAMENT,
        };

        return [
            'numero_autorisation' => $ligne['ZULASSUNGSNUMMER'],
            'nom' => mb_substr($nom, 0, 255),
            'nom_court' => self::nomCourt($nom),
            'forme' => $ligne['ARZNEIFORM'] ?: null,
            'atc_code' => $atc ?: null,
            'categorie' => $categorie,
            'titulaire' => $titulaires[$ligne['ZULASSUNGSINHABERIN'] ?? ''] ?? null,
            'statut' => $ligne['ZULASSUNGSSTATUS'] ?: null,
            'premiere_autorisation_le' => self::date($ligne['ERSTZULASSUNGSDATUM'] ?? ''),
            'expire_le' => self::date($ligne['ABLAUFDATUM'] ?? ''),
            'indication' => $ligne['ANWENDUNGSGEBIET'] ?: null,
            'liberation_lot' => ($ligne['CHARGENFREIGABE_PFLICHT'] ?? '') === 'X',
            'categorie_remise' => $ligne['ABGABEKATEGORIE'] ?: null,
            'especes' => self::especes($ligne['ZULASSUNGSNUMMER'], $nom, (string) $atc),
            'voies' => array_values(array_unique($voies)) ?: null,
            'source_le' => $exportLe,
            'retire_le' => null,
        ];
    }

    /** « Nobivac DHPPi ad us. vet., Lyophilisat … » → « Nobivac DHPPi ». */
    public static function nomCourt(string $nom): string
    {
        $court = preg_split('/\s+ad us\. vet\.?/iu', $nom)[0] ?? $nom;

        return mb_substr(trim($court, ' ,'), 0, 120) ?: mb_substr($nom, 0, 120);
    }

    /** @return list<string> */
    public static function especes(string $numero, string $nom, string $atc): array
    {
        if (isset(DonneesVaccins::PRODUITS[$numero]['especes'])) {
            return DonneesVaccins::PRODUITS[$numero]['especes'];
        }
        $trouvees = [];
        $mots = preg_split('/[^\p{L}]+/u', mb_strtolower($nom)) ?: [];
        foreach ($mots as $mot) {
            foreach (DonneesVaccins::ESPECES_PAR_MOT[$mot] ?? [] as $espece) {
                $trouvees[$espece] = true;
            }
        }
        if ($trouvees === []) {
            foreach (DonneesVaccins::ESPECES_PAR_ATC[substr($atc, 0, 4)] ?? [] as $espece) {
                $trouvees[$espece] = true;
            }
        }

        // Les tables curées ne citent que des codes Espece (vérifié par les tests du catalogue)
        return array_keys($trouvees);
    }

    private static function date(string $valeur): ?Carbon
    {
        if ($valeur === '' || str_starts_with($valeur, '9999')) {
            return null;
        }
        try {
            return Carbon::parse($valeur);
        } catch (Throwable) {
            return null;
        }
    }

    private function poserValences(): void
    {
        foreach (DonneesVaccins::VALENCES as $code => $valence) {
            Valence::query()->updateOrCreate(['code' => $code], ['nom' => $valence['nom'], 'nom_court' => $valence['court'], 'agent' => $valence['agent']]);
        }
    }

    /** Valences : la liste curée du produit, sinon déduites de la description ATCvet. */
    private function poserValencesDuProduit(ProduitVeterinaire $produit, string $descriptionAtc): void
    {
        $codes = DonneesVaccins::PRODUITS[$produit->numero_autorisation]['valences'] ?? self::valencesDepuisAtc($descriptionAtc);
        if ($codes === []) {
            return;
        }
        $ids = Valence::query()->whereIn('code', $codes)->pluck('id');
        $ajoutees = $produit->valences()->syncWithoutDetaching($ids)['attached'];
        // Une valence ajoutée au produit : les injections faites avec lui sans valences photographiées prennent
        // les siennes, « renouvelée » se refait ; rien d'ajouté, rien à refaire (vérification du 01.11.2026)
        if ($ajoutees !== []) {
            Vaccination::query()->withoutGlobalScopes()->where('produit_id', $produit->id)->whereNull('valences')
                ->distinct()->pluck('patient_id')->each(fn (int $patientId) => Vaccination::recalculerRenouvellements($patientId));
        }
    }

    /** @return list<string> */
    public static function valencesDepuisAtc(string $description): array
    {
        $codes = [];
        foreach (preg_split('/\s*\+\s*/', mb_strtoupper($description)) ?: [] as $morceau) {
            foreach (DonneesVaccins::AGENTS as $mot => $code) {
                if (str_contains($morceau, $mot)) {
                    $codes[$code] = true;
                    break;
                }
            }
        }

        return array_keys($codes);
    }

    private function poserSchema(ProduitVeterinaire $produit): void
    {
        $schema = DonneesVaccins::PRODUITS[$produit->numero_autorisation]['schema'] ?? null;
        if ($schema === null) {
            return;
        }
        VaccinSchema::query()->updateOrCreate(['produit_id' => $produit->id], [
            'age_min_semaines' => $schema['age'] ?? null,
            'primo_injections' => $schema['primo'] ?? null,
            'primo_intervalle_semaines' => $schema['intervalle'] ?? null,
            'rappel_premier_mois' => $schema['rappel_premier'] ?? null,
            'rappel_mois' => $schema['rappel'] ?? null,
            'voie' => $schema['voie'] ?? null,
            'remarques' => $schema['remarques'] ?? null,
            'source' => $schema['source'] ?? DonneesVaccins::SOURCE_INDICATIVE,
        ]);
    }

    /**
     * @param  Collection<string, ProduitVeterinaire>  $parNumero
     * @param  array<string, string>  $retraitsSequences
     */
    private function poserEmballages(string $chemin, $parNumero, array $retraitsSequences): void
    {
        foreach (LecteurXml::elements($chemin, 'PACKUNG') as $ligne) {
            $produit = $parNumero->get($ligne['ZULASSUNGSNUMMER'] ?? '');
            if ($produit === null) {
                continue;
            }
            $numero = str_pad($ligne['ZULASSUNGSNUMMER'], 5, '0', STR_PAD_LEFT).str_pad($ligne['PACKUNGSCODE'] ?? '0', 3, '0', STR_PAD_LEFT);
            $retrait = $ligne['WIDERRUF_VERZICHT_DATUM'] ?: ($retraitsSequences[$ligne['ZULASSUNGSNUMMER'].'|'.($ligne['SEQUENZNUMMER'] ?? '')] ?? '');
            ProduitEmballage::query()->updateOrCreate(
                ['produit_id' => $produit->id, 'numero_emballage' => $numero],
                [
                    'sequence' => $ligne['SEQUENZNUMMER'] ?? null,
                    'taille' => trim(($ligne['PACKUNGSGROESSE'] ?? '').' '.($ligne['BEMERKUNG_FREITEXT'] ?? '')) ?: null,
                    'unite' => $ligne['PACKUNGSEINHEIT'] ?? null,
                    'statut' => $ligne['ZULASSUNGSSTATUS'] ?? null,
                    'retire_le' => self::date($retrait),
                ],
            );
        }
    }

    /** @param  list<string>  $nouveauxVaccins */
    private function notifier(array $nouveauxVaccins): void
    {
        $destinataire = config('catalogue.notification_email');
        if ($nouveauxVaccins === [] || ! $destinataire) {
            return;
        }
        Mail::to($destinataire)->send(new NouveauxProduitsCatalogue($nouveauxVaccins));
    }

    private function clore(CatalogueImport $journal, int $debut): CatalogueImport
    {
        $journal->duree_ms = (int) ((hrtime(true) - $debut) / 1_000_000);
        $journal->save();

        return $journal;
    }
}
