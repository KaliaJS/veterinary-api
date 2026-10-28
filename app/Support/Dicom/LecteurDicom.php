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

    /** Séquences imbriquées au-delà : le fichier n'est pas lu plus loin (un vrai DICOM en a quelques-unes) */
    private const int PROFONDEUR_MAX = 16;

    private const string IMPLICITE = '1.2.840.10008.1.2';

    /** Grand-boutiste (retiré de la norme) et dégonflé : le jeu de données ne se lit pas */
    public const array SYNTAXES_ILLISIBLES = ['1.2.840.10008.1.2.2', '1.2.840.10008.1.2.1.99'];

    /** Fragments de pixels compressés parcourus au plus (un par image, plus la table) */
    private const int FRAGMENTS_MAX = 100_000;

    /** Taille du fichier entier : des pixels annoncés plus longs que lui, c'est un fichier coupé */
    private int $tailleFichier = 0;

    private ?string $chemin = null;

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
        $lecteur = new self;
        $lecteur->tailleFichier = (int) @filesize($chemin);
        $lecteur->chemin = $chemin;

        return $lecteur->analyser($octets);
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
        $lisible = ! in_array($syntaxe, self::SYNTAXES_ILLISIBLES, true);

        while ($lisible && $this->pos + 8 <= strlen($this->octets)) {
            $etiquette = $this->etiquetteSuivante();
            if ($etiquette === '7FE00010') {
                // Des pixels vides, ou qui dépassent la fin du fichier (il est coupé) : refusés
                $longueur = $explicite ? $this->entier32($this->pos + 8) : $this->entier32($this->pos + 4);
                $debut = $this->pos + ($explicite ? 12 : 8);
                $pixels = match (true) {
                    $longueur === null, $longueur === 0 => false,
                    $longueur === 0xFFFFFFFF => $this->fragmentsComplets($debut),
                    default => $debut + $longueur <= $this->tailleFichier,
                };
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
            // Grand-boutiste (retiré de la norme) ou dégonflé : le jeu de données
            // n'est pas lu, les pixels ne sont pas trouvés, le fichier est refusé
            'pixels' => $pixels,
        ];
    }

    /**
     * Pixels compressés : des fragments jusqu'au délimiteur (FFFE,E0DD),
     * tous entiers dans le fichier. Seuls leurs en-têtes sont lus : un
     * fichier de 100 Mo ne passe pas en mémoire.
     */
    private function fragmentsComplets(int $debut): bool
    {
        $flux = $this->chemin === null ? false : @fopen($this->chemin, 'rb');
        if ($flux === false) {
            return false;
        }
        try {
            $pos = $debut;
            for ($i = 0; $i < self::FRAGMENTS_MAX; $i++) {
                if (fseek($flux, $pos) !== 0) {
                    return false;
                }
                $entete = fread($flux, 8);
                if ($entete === false || strlen($entete) < 8) {
                    return false;
                }
                ['groupe' => $groupe, 'element' => $element, 'longueur' => $longueur] = unpack('vgroupe/velement/Vlongueur', $entete);
                if ($groupe !== 0xFFFE) {
                    return false;
                }
                if ($element === 0xE0DD) {
                    return true;
                }
                if ($element !== 0xE000 || $longueur === 0xFFFFFFFF) {
                    return false;
                }
                $pos += 8 + $longueur;
                if ($pos > $this->tailleFichier) {
                    return false;
                }
            }

            return false;
        } finally {
            fclose($flux);
        }
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
        return $this->entier16($this->pos) ?? -1;
    }

    private function etiquetteSuivante(): string
    {
        if ($this->pos + 4 > strlen($this->octets)) {
            return 'FIN';
        }

        return sprintf('%04X%04X', $this->entier16($this->pos), $this->entier16($this->pos + 2));
    }

    /** Lecture bornée : un fichier tronqué rend null, jamais une erreur */
    private function entier16(int $a): ?int
    {
        return $a + 2 <= strlen($this->octets) ? unpack('v', substr($this->octets, $a, 2))[1] : null;
    }

    private function entier32(int $a): ?int
    {
        return $a + 4 <= strlen($this->octets) ? unpack('V', substr($this->octets, $a, 4))[1] : null;
    }

    /**
     * Lit un élément et avance ; null si le fichier est tronqué ou illisible.
     *
     * @return array{0: string, 1: ?string, 2: string}|null
     */
    private function element(bool $explicite, int $profondeur = 0): ?array
    {
        $etiquette = $this->etiquetteSuivante();
        $this->pos += 4;
        $vr = null;
        if ($explicite) {
            $vr = substr($this->octets, $this->pos, 2);
            if (in_array($vr, self::VR_LONGS, true)) {
                $longueur = $this->entier32($this->pos + 4);
                $this->pos += 8;
            } elseif (preg_match('/^[A-Z]{2}$/', $vr) === 1) {
                $longueur = $this->entier16($this->pos + 2);
                $this->pos += 4;
            } else {
                return null;
            }
        } else {
            $longueur = $this->entier32($this->pos);
            $this->pos += 4;
        }
        if ($longueur === null) {
            return null;
        }

        if ($longueur === 0xFFFFFFFF) {
            // Séquence (ou valeur encapsulée) de longueur indéfinie : sautée.
            // Un UN de longueur indéfinie se lit en VR implicite (norme, PS3.5 §6.2.2)
            return $this->sauterIndefini($explicite && $vr !== 'UN', $profondeur + 1) ? [$etiquette, $vr, ''] : null;
        }
        if ($this->pos + $longueur > strlen($this->octets)) {
            return null;
        }
        $valeur = substr($this->octets, $this->pos, $longueur);
        $this->pos += $longueur;

        return [$etiquette, $vr, $valeur];
    }

    /** Saute une séquence de longueur indéfinie, jusqu'à son délimiteur (FFFE,E0DD) */
    private function sauterIndefini(bool $explicite, int $profondeur): bool
    {
        if ($profondeur > self::PROFONDEUR_MAX) {
            return false;
        }
        while ($this->pos + 8 <= strlen($this->octets)) {
            $etiquette = $this->etiquetteSuivante();
            $longueur = (int) $this->entier32($this->pos + 4);
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
                if ($this->element($explicite, $profondeur) === null) {
                    return false;
                }
            }
            $this->pos += 8;
        }

        return false;
    }
}
