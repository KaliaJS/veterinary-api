<?php

namespace Tests\Doubles;

/**
 * De petits enregistrements de structure réelle, d'une durée choisie, pour
 * les messages audio et vidéo (07.11.2026) : WebM comme Chrome les écrit
 * (Segment et Clusters de taille inconnue, sans durée dans Info), MP4
 * fragmenté comme Chrome et Safari (ou entier, durée dans `mvhd`), Ogg Opus
 * comme Firefox. Complétés à la taille voulue (élément Void, boîte `free`).
 */
final class EnregistrementFactice
{
    public static function webm(int $ms, int $octets = 4000, string $docType = 'webm', ?int $infoDureeMs = null): string
    {
        $header = self::ebml("\x1A\x45\xDF\xA3", self::ebml("\x42\x86", "\x01").self::ebml("\x42\x82", $docType));
        $info = self::ebml("\x2A\xD7\xB1", "\x0F\x42\x40")
            .($infoDureeMs !== null ? self::ebml("\x44\x89", pack('E', (float) $infoDureeMs)) : '');
        $clusters = '';
        // Un cluster toutes les 30 s (temps relatif sur 16 bits), le dernier bloc à la fin
        for ($t = 0; $t < $ms; $t += 30000) {
            $clusters .= self::cluster($t, 0);
        }
        $clusters .= self::cluster($ms, 0);
        $segment = self::ebml("\x15\x49\xA9\x66", $info);
        $tete = $header."\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".$segment;
        $reste = $octets - strlen($tete) - strlen($clusters);
        $void = $reste > 9 ? "\xEC".self::taille($reste - 9).str_repeat("\x00", $reste - 9) : '';

        return $tete.$void.$clusters;
    }

    /** Le début d'un WebM sans aucun bloc : sa durée ne se lit pas */
    public static function webmSansBloc(int $octets = 4000): string
    {
        $tete = self::ebml("\x1A\x45\xDF\xA3", self::ebml("\x42\x82", 'webm'))."\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF";

        return $tete."\xEC".self::taille(max(0, $octets - strlen($tete) - 9)).str_repeat("\x00", max(0, $octets - strlen($tete) - 9));
    }

    public static function mp4(int $ms, int $octets = 4000, string $marque = 'iso5', bool $fragmente = true): string
    {
        $ftyp = self::box('ftyp', $marque."\x00\x00\x02\x00".$marque.'iso6mp41');
        $mvhd = self::box('mvhd', "\x00\x00\x00\x00".pack('NNNN', 0, 0, 1000, $fragmente ? 0 : $ms).str_repeat("\x00", 80));
        $tkhd = self::box('tkhd', "\x00\x00\x00\x07".pack('NNN', 0, 0, 1).str_repeat("\x00", 68));
        $mdhd = self::box('mdhd', "\x00\x00\x00\x00".pack('NNNN', 0, 0, 48000, $fragmente ? 0 : $ms * 48).pack('N', 0));
        $moov = self::box('moov', $mvhd.self::box('trak', $tkhd.self::box('mdia', $mdhd)).($fragmente ? self::box('mvex', self::box('trex', str_repeat("\x00", 24))) : ''));
        $fragments = '';
        if ($fragmente) {
            // Deux fragments : le second commence où le premier s'arrête (tfdt)
            $moitie = intdiv($ms, 2);
            foreach ([[0, $moitie], [$moitie, $ms - $moitie]] as [$debut, $duree]) {
                $traf = self::box('tfhd', "\x00\x02\x00\x00".pack('N', 1))
                    .self::box('tfdt', "\x01\x00\x00\x00".pack('J', $debut * 48))
                    .self::box('trun', "\x00\x00\x01\x00".pack('NN', 1, $duree * 48));
                $fragments .= self::box('moof', self::box('mfhd', "\x00\x00\x00\x00".pack('N', 1)).self::box('traf', $traf)).self::box('mdat', "\x00\x00");
            }
        }
        $reste = $octets - strlen($ftyp) - strlen($moov) - strlen($fragments);

        return $ftyp.$moov.($reste >= 8 ? self::box('free', str_repeat("\x00", $reste - 8)) : '').$fragments;
    }

    /** Ogg Opus en pages valides (CRC juste) : l'en-tête, des pages de son, la dernière au granule de la durée */
    public static function ogg(int $ms, int $octets = 4000, ?string $pageFinaleForgee = null): string
    {
        $tete = self::page("\x02", 0, 0, 'OpusHead'."\x01\x01".pack('v', 312).str_repeat("\x00", 9));
        $fin = 312 + $ms * 48;
        $corps = '';
        $sequence = 1;
        $reste = $octets - strlen($tete);
        while ($reste > 27 + 255 + 300) {
            $taille = min(60000, $reste - 27 - 255 - 300);
            $corps .= self::page("\x00", intdiv($fin * $sequence, 1000), $sequence++, str_repeat("\x01", $taille));
            $reste = $octets - strlen($tete) - strlen($corps);
        }

        return $tete.$corps.self::page("\x04", $fin, $sequence, 'fin').($pageFinaleForgee ?? '');
    }

    /** Une page Ogg forgée, de granule choisi, au CRC juste ou faux */
    public static function pageOgg(int $granule, bool $crcJuste = true): string
    {
        $page = self::page("\x04", $granule, 99, 'x');

        return $crcJuste ? $page : substr_replace($page, "\x00\x00\x00\x00", 22, 4);
    }

    /** Un WebM dont tous les blocs sont au temps 0 : des minutes de son qui se disent instantanées */
    public static function webmBlocsAuTempsZero(int $blocs = 3000): string
    {
        $header = self::ebml("\x1A\x45\xDF\xA3", self::ebml("\x42\x82", 'webm'));
        $blocsAZero = str_repeat(self::ebml("\xA3", "\x81\x00\x00\x80opus"), $blocs);

        return $header."\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".self::ebml("\x15\x49\xA9\x66", self::ebml("\x2A\xD7\xB1", "\x0F\x42\x40"))
            ."\x1F\x43\xB6\x75\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".self::ebml("\xE7", "\x00").$blocsAZero;
    }

    /**
     * Un WebM dont chaque bloc porte un numéro de piste différent, codé sur
     * 3 octets (7 octets par bloc) ; ou, `declarer`, une piste déclarée dans
     * Tracks et un bloc d'une autre.
     */
    public static function webmPistesInnombrables(int $blocs, bool $declarer = false, int $pisteDuBloc = 0): string
    {
        $header = self::ebml("\x1A\x45\xDF\xA3", self::ebml("\x42\x82", 'webm'));
        $tracks = $declarer ? self::ebml("\x16\x54\xAE\x6B", self::ebml("\xAE", self::ebml("\xD7", "\x01"))) : '';
        $corps = '';
        for ($i = 0; $i < $blocs; $i++) {
            $piste = $declarer ? $pisteDuBloc : $i + 1;
            $corps .= "\xA3\x85".chr(0x20 | ($piste >> 16)).chr(($piste >> 8) & 0xFF).chr($piste & 0xFF)."\x00\x00";
        }

        return $header."\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".$tracks
            ."\x1F\x43\xB6\x75\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".self::ebml("\xE7", "\x00").$corps;
    }

    /**
     * Un AAC de Safari, entier : `$kbits` kbit/s de son pendant `$ms`, une
     * table `stsz` de 4 octets par trame (1024 échantillons à 48 kHz).
     */
    public static function aac(int $ms, int $kbits): string
    {
        $trames = (int) ceil($ms / 1000 * 48000 / 1024);
        $ftyp = self::box('ftyp', 'M4A '."\x00\x00\x02\x00".'M4A mp42isom');
        $mvhd = self::box('mvhd', "\x00\x00\x00\x00".pack('NNNN', 0, 0, 1000, $ms).str_repeat("\x00", 80));
        $tkhd = self::box('tkhd', "\x00\x00\x00\x07".pack('NNN', 0, 0, 1).str_repeat("\x00", 68));
        $mdhd = self::box('mdhd', "\x00\x00\x00\x00".pack('NNNN', 0, 0, 48000, $ms * 48).pack('N', 0));
        $stts = self::box('stts', "\x00\x00\x00\x00".pack('N', 1).pack('NN', $trames, 1024));
        $stsz = self::box('stsz', "\x00\x00\x00\x00".pack('NN', 0, $trames).str_repeat(pack('N', 512), $trames));
        $moov = self::box('moov', $mvhd.self::box('trak', $tkhd.self::box('mdia', $mdhd.self::box('minf', self::box('stbl', $stts.$stsz)))));

        return $ftyp.$moov.self::box('mdat', str_repeat("\x00", intdiv($ms * $kbits, 8)));
    }

    /** Un MP4 « vidéo » d'une seule boîte `$type` géante (forgé, 20 Mo) */
    public static function mp4BoiteGeante(string $type, int $octets): string
    {
        $ftyp = self::box('ftyp', 'isom'."\x00\x00\x02\x00".'isom');

        return $ftyp.self::box('moov', self::box('trak', self::box('mdia', self::box('minf', self::box('stbl', self::box($type, "\x00\x00\x00\x00".pack('N', 0xFFFFFFFF).str_repeat("\x01", $octets - strlen($ftyp) - 56)))))));
    }

    /** Un MP4 entier dont `mvhd` et `mdhd` disent `$annonceMs`, mais dont la table `stts` dure `$reelMs` */
    public static function mp4Stts(int $annonceMs, int $reelMs): string
    {
        $ftyp = self::box('ftyp', 'iso5'."\x00\x00\x02\x00".'iso5mp41');
        $mvhd = self::box('mvhd', "\x00\x00\x00\x00".pack('NNNN', 0, 0, 1000, $annonceMs).str_repeat("\x00", 80));
        $tkhd = self::box('tkhd', "\x00\x00\x00\x07".pack('NNN', 0, 0, 1).str_repeat("\x00", 68));
        $mdhd = self::box('mdhd', "\x00\x00\x00\x00".pack('NNNN', 0, 0, 48000, $annonceMs * 48).pack('N', 0));
        // Des échantillons de 20 ms (960 à 48 kHz)
        $stts = self::box('stts', "\x00\x00\x00\x00".pack('N', 1).pack('NN', intdiv($reelMs, 20), 960));
        $stbl = self::box('stbl', $stts);
        $moov = self::box('moov', $mvhd.self::box('trak', $tkhd.self::box('mdia', $mdhd.self::box('minf', $stbl))));

        return $ftyp.$moov.self::box('mdat', str_repeat("\x00", 2000));
    }

    private static function page(string $type, int $granule, int $sequence, string $donnees): string
    {
        $segments = [];
        $n = strlen($donnees);
        while ($n >= 255) {
            $segments[] = 255;
            $n -= 255;
        }
        $segments[] = $n;
        $page = 'OggS'."\x00".$type.pack('P', $granule).pack('V', 1234).pack('V', $sequence)."\x00\x00\x00\x00"
            .chr(count($segments)).implode('', array_map('chr', $segments)).$donnees;

        return substr_replace($page, pack('V', self::crcOgg($page)), 22, 4);
    }

    /** Le CRC des pages Ogg (polynôme 0x04C11DB7, sans réflexion) */
    private static function crcOgg(string $data): int
    {
        $crc = 0;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 24;
            for ($b = 0; $b < 8; $b++) {
                $crc = $crc & 0x80000000 ? (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF : ($crc << 1) & 0xFFFFFFFF;
            }
        }

        return $crc;
    }

    private static function cluster(int $temps, int $relatif): string
    {
        $bloc = "\x81".pack('n', $relatif)."\x80".'opus';

        return "\x1F\x43\xB6\x75\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".self::ebml("\xE7", pack('N', $temps)).self::ebml("\xA3", $bloc);
    }

    private static function ebml(string $id, string $data): string
    {
        return $id.self::taille(strlen($data)).$data;
    }

    /** Une taille EBML sur 8 octets */
    private static function taille(int $n): string
    {
        return "\x01".substr(pack('J', $n), 1);
    }

    private static function box(string $type, string $data): string
    {
        return pack('N', 8 + strlen($data)).$type.$data;
    }
}
