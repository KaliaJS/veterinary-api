<?php

namespace Tests\Concerns;

/**
 * Fichiers DICOM d'essai, écrits octet par octet (28.10.2026) : en-tête
 * de fichier (groupe 0002), puis le jeu de données en VR explicite ou
 * implicite petit-boutiste, une séquence de longueur indéfinie pour
 * éprouver la lecture, et des pixels 16 bits.
 */
trait FabriqueDicom
{
    /**
     * @param  array<string, string|int>  $champs  clés : sop, etude, serie, modalite, region, incidence, lateralite, lignes, colonnes, images
     */
    protected function dicom(array $champs = [], string $syntaxe = '1.2.840.10008.1.2.1', bool $avecPixels = true): string
    {
        $c = $champs + [
            'sop' => '1.2.826.0.1.3680043.8.498.1001',
            'etude' => '1.2.826.0.1.3680043.8.498.2001',
            'serie' => '1.2.826.0.1.3680043.8.498.3001',
            'modalite' => 'DX',
            'lignes' => 8,
            'colonnes' => 8,
        ];
        $classe = '1.2.840.10008.5.1.4.1.1.1.1';

        $meta = $this->element(0x0002, 0x0001, 'OB', "\x00\x01")
            .$this->element(0x0002, 0x0002, 'UI', $classe)
            .$this->element(0x0002, 0x0003, 'UI', (string) $c['sop'])
            .$this->element(0x0002, 0x0010, 'UI', $syntaxe)
            .$this->element(0x0002, 0x0012, 'UI', '1.2.826.0.1.3680043.8.498.1');
        $meta = $this->element(0x0002, 0x0000, 'UL', pack('V', strlen($meta))).$meta;

        $implicite = $syntaxe === '1.2.840.10008.1.2';
        $e = fn (int $g, int $el, string $vr, string $valeur): string => $implicite ? $this->elementImplicite($g, $el, $valeur) : $this->element($g, $el, $vr, $valeur);

        $donnees = $e(0x0008, 0x0016, 'UI', $classe)
            .$e(0x0008, 0x0018, 'UI', (string) $c['sop'])
            .$e(0x0008, 0x0060, 'CS', (string) $c['modalite'])
            .$this->sequenceIndefinie($implicite)
            .$e(0x0010, 0x0010, 'PN', 'FELIX^MARTIN');
        if (isset($c['region'])) {
            $donnees .= $e(0x0018, 0x0015, 'CS', (string) $c['region']);
        }
        if (isset($c['incidence'])) {
            $donnees .= $e(0x0018, 0x5101, 'CS', (string) $c['incidence']);
        }
        $donnees .= $e(0x0020, 0x000D, 'UI', (string) $c['etude'])
            .$e(0x0020, 0x000E, 'UI', (string) $c['serie']);
        if (isset($c['lateralite'])) {
            $donnees .= $e(0x0020, 0x0060, 'CS', (string) $c['lateralite']);
        }
        if (isset($c['images'])) {
            $donnees .= $e(0x0028, 0x0008, 'IS', (string) $c['images']);
        }
        $donnees .= $e(0x0028, 0x0010, 'US', pack('v', (int) $c['lignes']))
            .$e(0x0028, 0x0011, 'US', pack('v', (int) $c['colonnes']));
        if ($avecPixels) {
            $donnees .= $e(0x7FE0, 0x0010, 'OW', str_repeat("\x34\x12", (int) $c['lignes'] * (int) $c['colonnes']));
        }

        return str_repeat("\x00", 128).'DICM'.$meta.$donnees;
    }

    private function element(int $groupe, int $numero, string $vr, string $valeur): string
    {
        $valeur = $this->pair($vr, $valeur);
        $longue = in_array($vr, ['OB', 'OW', 'OF', 'SQ', 'UT', 'UN'], true);

        return pack('vv', $groupe, $numero).$vr.($longue ? "\x00\x00".pack('V', strlen($valeur)) : pack('v', strlen($valeur))).$valeur;
    }

    private function elementImplicite(int $groupe, int $numero, string $valeur): string
    {
        $valeur = strlen($valeur) % 2 === 1 ? $valeur."\x00" : $valeur;

        return pack('vvV', $groupe, $numero, strlen($valeur)).$valeur;
    }

    /** (0008,1140) Referenced Image Sequence, longueur indéfinie, un élément de longueur indéfinie */
    private function sequenceIndefinie(bool $implicite): string
    {
        $interne = $implicite ? $this->elementImplicite(0x0008, 0x1150, '1.2.3') : $this->element(0x0008, 0x1150, 'UI', '1.2.3');
        $item = pack('vvV', 0xFFFE, 0xE000, 0xFFFFFFFF).$interne.pack('vvV', 0xFFFE, 0xE00D, 0);
        $entete = $implicite ? pack('vvV', 0x0008, 0x1140, 0xFFFFFFFF) : pack('vv', 0x0008, 0x1140).'SQ'."\x00\x00".pack('V', 0xFFFFFFFF);

        return $entete.$item.pack('vvV', 0xFFFE, 0xE0DD, 0);
    }

    private function pair(string $vr, string $valeur): string
    {
        if (strlen($valeur) % 2 === 0) {
            return $valeur;
        }

        return $valeur.(in_array($vr, ['UI', 'OB', 'OW', 'UN'], true) ? "\x00" : ' ');
    }
}
