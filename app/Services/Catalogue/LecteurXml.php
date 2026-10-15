<?php

namespace App\Services\Catalogue;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Lecture des archives et des XML des flux Swissmedic et Refdata, sans
 * bibliothèque : ZipArchive et XMLReader de PHP, en flux (mémoire
 * constante), entités externes jamais chargées. L'archive est vérifiée
 * avant toute lecture : zip valide, aucune entrée inattendue (pas de
 * macro, pas de chemin relatif), tailles bornées.
 */
final class LecteurXml
{
    /**
     * @param  list<string>  $attendus  noms d entrées exigés
     * @return array<string, string> nom d entrée → chemin extrait
     */
    public static function extraire(string $archive, string $dossier, array $attendus, int $tailleMax): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Archive illisible');
        }
        $noms = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $nom = $stat['name'];
            if (str_contains($nom, '..') || str_starts_with($nom, '/') || ! preg_match('/\.(xml|xsd)$/i', $nom)) {
                throw new RuntimeException("Entrée inattendue dans l archive : {$nom}");
            }
            if ($stat['size'] > $tailleMax) {
                throw new RuntimeException("Entrée trop volumineuse : {$nom}");
            }
            $noms[] = $nom;
        }
        foreach ($attendus as $attendu) {
            if (! in_array($attendu, $noms, true)) {
                throw new RuntimeException("Entrée absente de l archive : {$attendu}");
            }
        }
        if (! is_dir($dossier) && ! mkdir($dossier, 0750, true)) {
            throw new RuntimeException('Dossier de travail impossible');
        }
        $chemins = [];
        foreach ($attendus as $attendu) {
            $cible = $dossier.'/'.basename($attendu);
            $flux = $zip->getStream($attendu);
            if ($flux === false) {
                throw new RuntimeException("Entrée illisible : {$attendu}");
            }
            $sortie = fopen($cible, 'wb');
            stream_copy_to_stream($flux, $sortie);
            fclose($flux);
            fclose($sortie);
            $chemins[$attendu] = $cible;
        }
        $zip->close();

        return $chemins;
    }

    /**
     * Itère les éléments `$balise` d un fichier XML, chacun rendu comme un
     * tableau nom de sous-élément → texte (sans espace de noms).
     *
     * @return Generator<int, array<string, string>>
     */
    public static function elements(string $chemin, string $balise): Generator
    {
        foreach (self::arbres($chemin, $balise) as $element) {
            $valeurs = [];
            foreach ($element->children() as $enfant) {
                $valeurs[$enfant->getName()] = trim((string) $enfant);
            }
            yield $valeurs;
        }
    }

    /**
     * Variante hiérarchique : rend l élément SimpleXML entier (espaces de
     * noms retirés), pour les flux imbriqués comme Refdata.
     *
     * @return Generator<int, \SimpleXMLElement>
     */
    public static function arbres(string $chemin, string $balise): Generator
    {
        $lecteur = new XMLReader;
        if (! $lecteur->open($chemin, null, LIBXML_NONET)) {
            throw new RuntimeException("XML illisible : {$chemin}");
        }
        try {
            // (après un élément rendu, `next()` se place sur le suivant : pas
            // de `read()` supplémentaire, qui entrerait dans ses enfants et
            // sauterait un élément sur deux)
            $ok = $lecteur->read();
            while ($ok) {
                if ($lecteur->nodeType !== XMLReader::ELEMENT || $lecteur->localName !== $balise) {
                    $ok = $lecteur->read();

                    continue;
                }
                $xml = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $lecteur->readOuterXml());
                $element = simplexml_load_string($xml, options: LIBXML_NONET);
                if ($element !== false) {
                    yield $element;
                }
                $ok = $lecteur->next();
            }
        } finally {
            $lecteur->close();
        }
    }
}
