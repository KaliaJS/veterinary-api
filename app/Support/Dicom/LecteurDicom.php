<?php

namespace App\Support\Dicom;

/**
 * Lit l'en-tête d'un fichier DICOM (28.10.2026), sans jamais le modifier :
 * les identifiants, la modalité, les dimensions et les repères (région,
 * incidence, côté) qu'il porte. Lecture seule des premiers mégaoctets,
 * arrêtée aux pixels (7FE0,0010) : un fichier de 100 Mo ne passe pas en
 * mémoire. VR explicite ou implicite, petit-boutiste (toutes les syntaxes
 * compressées le sont) ; séquences de longueur indéfinie sautées.
 */
final class LecteurDicom
{
    /** Ce qu'on lit avant les pixels ; au-delà, un en-tête n'a plus rien d'utile */
    private const int LECTURE_MAX = 8 * 1024 * 1024;

    private const string IMPLICITE = '1.2.840.10008.1.2';

    private const string GRAND_BOUTISTE = '1.2.840.10008.1.2.2';

    /** VR à longueur sur 4 octets (2 réservés avant) en VR explicite */
    private const array VR_LONGS = ['OB', 'OD', 'OF', 'OL', 'OV', 'OW', 'SQ', 'SV', 'UC', 'UN', 'UR', 'UT', 'UV'];

    /** Étiquette (groupe élément) => champ rendu */
    private const array CHAMPS = [
        '00020010' => 'syntaxe_transfert',
        '00020003' => 'sop_meta',
        '00080018' => 'sop_instance_uid',
        '0020000D' => 'study_instance_uid',
        '0020000E' => 'series_instance_uid',
        '00080060' => 'modalite',
        '00180015' => 'body_part',
        '00185101' => 'view_position',
        '00200060' => 'lateralite',
        '00280008' => 'images',
        '00280010' => 'lignes',
        '00280011' => 'colonnes',
    ];

    /** Entiers sur 2 octets (US) */
    private const array US = ['00280010', '00280011'];

    private string $octets = '';

    private int $pos = 0;

    /**
     * @return array{sop_instance_uid: ?string, study_instance_uid: ?string, series_instance_uid: ?string, modalite: ?string, body_part: ?string, view_position: ?string, lateralite: ?string, images: int, lignes: ?int, colonnes: ?int, syntaxe_transfert: ?string, pixels: bool}|null null : pas un fichier DICOM
     */
    public static function lire(string $chemin): ?array
    {
        $flux = @fopen($chemin, 'rb');
        if ($flux === false) {
            return null;
        }
        $octets = (string) fread($flux, self::LECTURE_MAX);
        fclose($flux);

        return (new self)->analyser($octets);
    }

    /** @return array<string, mixed>|null */
    private function analyser(string $octets): ?array
    {
        if (strlen($octets) < 132 || substr($octets, 128, 4) !== 'DICM') {
            return null;
        }
        $this->octets = $octets;
        $this->pos = 132;
        $lu = [];
        $pixels = false;

        // En-tête de fichier (groupe 0002) : toujours en VR explicite petit-boutiste
        while ($this->pos + 8 <= strlen($this->octets) && $this->groupeSuivant() === 0x0002) {
            $element = $this->element(explicite: true);
            if ($element === null) {
                return null;
            }
            $this->garder($lu, $element);
        }

        $syntaxe = $lu['syntaxe_transfert'] ?? null;
        $explicite = $syntaxe !== self::IMPLICITE;
        // Grand-boutiste (retiré du standard) ou dégonflé : seul l'en-tête se lit
        $lisible = $syntaxe !== self::GRAND_BOUTISTE && $syntaxe !== '1.2.840.10008.1.2.1.99';

        while ($lisible && $this->pos + 8 <= strlen($this->octets)) {
            $etiquette = $this->etiquetteSuivante();
            if ($etiquette === '7FE00010') {
                $pixels = true;
                break;
            }
            $element = $this->element($explicite);
            if ($element === null) {
                break;
            }
            $this->garder($lu, $element);
        }

        return [
            'sop_instance_uid' => $lu['sop_instance_uid'] ?? $lu['sop_meta'] ?? null,
            'study_instance_uid' => $lu['study_instance_uid'] ?? null,
            'series_instance_uid' => $lu['series_instance_uid'] ?? null,
            'modalite' => $lu['modalite'] ?? null,
            'body_part' => $lu['body_part'] ?? null,
            'view_position' => $lu['view_position'] ?? null,
            'lateralite' => $lu['lateralite'] ?? null,
            'images' => max(1, (int) ($lu['images'] ?? 1)),
            'lignes' => isset($lu['lignes']) ? (int) $lu['lignes'] : null,
            'colonnes' => isset($lu['colonnes']) ? (int) $lu['colonnes'] : null,
            'syntaxe_transfert' => $syntaxe,
            // Lecture dégonflée impossible : la présence des pixels ne se vérifie pas
            'pixels' => $pixels || ! $lisible,
        ];
    }

    /** @param  array<string, mixed>  $lu @param  array{0: string, 1: ?string, 2: string}  $element */
    private function garder(array &$lu, array $element): void
    {
        [$etiquette, , $valeur] = $element;
        $champ = self::CHAMPS[$etiquette] ?? null;
        if ($champ === null) {
            return;
        }
        if (in_array($etiquette, self::US, true)) {
            $lu[$champ] = strlen($valeur) >= 2 ? unpack('v', $valeur)[1] : null;

            return;
        }
        $texte = trim(str_replace("\0", '', $valeur));
        $lu[$champ] = $texte === '' ? null : $texte;
    }

    private function groupeSuivant(): int
    {
        return unpack('v', substr($this->octets, $this->pos, 2))[1];
    }

    private function etiquetteSuivante(): string
    {
        [, $groupe, $numero] = unpack('v2', substr($this->octets, $this->pos, 4));

        return sprintf('%04X%04X', $groupe, $numero);
    }

    /**
     * Lit un élément et avance ; null si le fichier est tronqué ou illisible.
     *
     * @return array{0: string, 1: ?string, 2: string}|null
     */
    private function element(bool $explicite): ?array
    {
        $etiquette = $this->etiquetteSuivante();
        $this->pos += 4;
        $vr = null;
        if ($explicite) {
            $vr = substr($this->octets, $this->pos, 2);
            if (in_array($vr, self::VR_LONGS, true)) {
                $longueur = unpack('V', substr($this->octets, $this->pos + 4, 4))[1];
                $this->pos += 8;
            } elseif (preg_match('/^[A-Z]{2}$/', $vr) === 1) {
                $longueur = unpack('v', substr($this->octets, $this->pos + 2, 2))[1];
                $this->pos += 4;
            } else {
                return null;
            }
        } else {
            $longueur = unpack('V', substr($this->octets, $this->pos, 4))[1];
            $this->pos += 4;
        }

        if ($longueur === 0xFFFFFFFF) {
            // Séquence (ou valeur encapsulée) de longueur indéfinie : sautée
            return $this->sauterIndefini($explicite) ? [$etiquette, $vr, ''] : null;
        }
        if ($this->pos + $longueur > strlen($this->octets)) {
            return null;
        }
        $valeur = substr($this->octets, $this->pos, $longueur);
        $this->pos += $longueur;

        return [$etiquette, $vr, $valeur];
    }

    /** Saute une séquence de longueur indéfinie, jusqu'à son délimiteur (FFFE,E0DD) */
    private function sauterIndefini(bool $explicite): bool
    {
        while ($this->pos + 8 <= strlen($this->octets)) {
            $etiquette = $this->etiquetteSuivante();
            $longueur = unpack('V', substr($this->octets, $this->pos + 4, 4))[1];
            $this->pos += 8;
            if ($etiquette === 'FFFEE0DD') {
                return true;
            }
            if ($etiquette !== 'FFFEE000') {
                return false;
            }
            if ($longueur !== 0xFFFFFFFF) {
                $this->pos += $longueur;

                continue;
            }
            // Élément de longueur indéfinie : ses éléments, jusqu'à (FFFE,E00D)
            while ($this->pos + 8 <= strlen($this->octets) && $this->etiquetteSuivante() !== 'FFFEE00D') {
                if ($this->element($explicite) === null) {
                    return false;
                }
            }
            $this->pos += 8;
        }

        return false;
    }
}
