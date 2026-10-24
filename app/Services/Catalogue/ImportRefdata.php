<?php

namespace App\Services\Catalogue;

use App\Models\CatalogueImport;
use App\Models\ProduitEmballage;
use App\Support\MasquageSql;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Import de la base d articles Refdata (ZIP d XML quotidien, clé de
 * souscription) : pour chaque emballage vétérinaire, son GTIN, son nom en
 * français et ses dates de commercialisation, posés sur l emballage
 * Swissmedic correspondant (numéro à 8 chiffres : produit + emballage).
 * Les emballages inconnus de Swissmedic (pas encore importés) sont
 * comptés, pas créés : Swissmedic reste la référence.
 */
final class ImportRefdata
{
    public function importer(?string $fichierLocal = null, bool $forcer = false): CatalogueImport
    {
        $debut = hrtime(true);
        $journal = new CatalogueImport(['source' => CatalogueImport::REFDATA, 'statut' => CatalogueImport::ECHEC]);
        $dossier = storage_path('app/catalogue/refdata');
        try {
            $archive = $fichierLocal ?? $this->telecharger($dossier);
            $journal->fichier_hash = hash_file('sha256', $archive);
            $precedent = CatalogueImport::query()
                ->where('source', CatalogueImport::REFDATA)
                ->whereIn('statut', [CatalogueImport::REUSSI, CatalogueImport::IDENTIQUE])
                ->latest('id')->first();
            if (! $forcer && $precedent && $precedent->fichier_hash === $journal->fichier_hash) {
                $journal->statut = CatalogueImport::IDENTIQUE;
                $journal->message = 'Fichier identique au dernier import';

                return $this->clore($journal, $debut);
            }
            $chemins = LecteurXml::extraire($archive, $dossier.'/xml', ['Refdata.Articles.xml'], config('catalogue.refdata.taille_max_entree'));
            $resultat = DB::transaction(fn (): array => $this->integrer($chemins['Refdata.Articles.xml'], $journal));
            $journal->fill($resultat)->statut = CatalogueImport::REUSSI;
            $this->clore($journal, $debut);
        } catch (Throwable $erreur) {
            $journal->message = mb_substr(MasquageSql::message($erreur->getMessage()), 0, 2000);
            $this->clore($journal, $debut);
            throw $erreur;
        } finally {
            // Rien n est conservé sur le disque : ni les XML extraits ni l archive téléchargée
            File::deleteDirectory($dossier.'/xml');
            if ($fichierLocal === null) {
                File::delete($dossier.'/Refdata.Articles.zip');
            }
        }

        return $journal;
    }

    /**
     * Télécharge l archive, toujours en entier : Refdata n annonce ni date ni
     * ETag et ignore les requêtes conditionnelles ; c est l empreinte SHA-256
     * locale qui dit ensuite si le fichier a changé.
     */
    private function telecharger(string $dossier): string
    {
        $cle = config('catalogue.refdata.cle');
        if (! $cle) {
            throw new RuntimeException('Clé Refdata absente (REFDATA_API_KEY)');
        }
        File::ensureDirectoryExists($dossier);
        $cible = $dossier.'/Refdata.Articles.zip';
        $reponse = Http::timeout(300)->withUserAgent('veterinary-api')
            ->withHeaders(['X-API-Key' => $cle, 'Cache-Control' => 'no-cache'])
            ->sink($cible)->get(config('catalogue.refdata.url'));
        if (! $reponse->successful()) {
            throw new RuntimeException('Téléchargement Refdata en échec : HTTP '.$reponse->status());
        }
        if (filesize($cible) > config('catalogue.refdata.taille_max')) {
            throw new RuntimeException('Archive Refdata plus grande qu attendu');
        }

        return $cible;
    }

    /** @return array{ajoutes: int, modifies: int, retires: int, message: string} */
    private function integrer(string $chemin, CatalogueImport $journal): array
    {
        $emballages = ProduitEmballage::query()->get()->keyBy('numero_emballage');
        $modifies = 0;
        $inconnus = 0;
        $vus = 0;
        foreach (LecteurXml::arbres($chemin, 'Article') as $article) {
            if ((string) $article->MedicinalProduct->Domain !== 'Veterinary') {
                continue;
            }
            $vus++;
            $paquet = $article->PackagedProduct;
            $numero = str_pad((string) $paquet->RegulatedAuthorisationIdentifier, 8, '0', STR_PAD_LEFT);
            $emballage = $emballages->get($numero);
            if ($emballage === null) {
                $inconnus++;

                continue;
            }
            $nomFr = null;
            foreach ($paquet->Name as $nom) {
                if ((string) $nom->Language === 'FR') {
                    $nomFr = (string) $nom->FullName;
                }
            }
            $gtin = preg_replace('/\D/', '', (string) $paquet->DataCarrierIdentifier) ?: null;
            $emballage->fill([
                'gtin' => $gtin,
                'nom_fr' => $nomFr ? mb_substr($nomFr, 0, 255) : null,
                'commercialise_le' => self::date((string) $paquet->MarketingStatus->DateStart),
                'arrete_le' => self::date((string) $paquet->MarketingStatus->DateStop),
            ]);
            if ($emballage->isDirty()) {
                $emballage->save();
                $modifies++;
            }
        }
        if ($journal->export_le === null) {
            $journal->export_le = aujourdhui();
        }

        return ['ajoutes' => 0, 'modifies' => $modifies, 'retires' => 0,
            'message' => "{$vus} emballages vétérinaires lus, {$inconnus} sans correspondance Swissmedic"];
    }

    private static function date(string $valeur): ?Carbon
    {
        if ($valeur === '') {
            return null;
        }
        try {
            return Carbon::parse($valeur);
        } catch (Throwable) {
            return null;
        }
    }

    private function clore(CatalogueImport $journal, int $debut): CatalogueImport
    {
        $journal->duree_ms = (int) ((hrtime(true) - $debut) / 1_000_000);
        $journal->save();

        return $journal;
    }
}
