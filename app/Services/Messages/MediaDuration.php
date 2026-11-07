<?php

namespace App\Services\Messages;

/**
 * La vraie durée d'un enregistrement, lue dans le fichier, sans ffprobe
 * (vérifications du 07.11.2026) : un « fichier d'une seconde » qui dure des
 * heures ne passe pas, et aucun fichier forgé ne bloque le serveur.
 *
 * - WebM (Chrome, Firefox) : MediaRecorder n'écrit pas la durée (flux en
 *   direct, tailles inconnues) ; on la lit au plus grand temps de tous les
 *   blocs (Cluster Timecode + temps relatif), ou à Info Duration quand elle
 *   y est. Des blocs d'une piste qui ne progressent pas (tous au temps 0)
 *   rendent le fichier illisible.
 * - MP4 (Safari, iPhone, Chrome récent) : la plus longue de `mvhd`, `mdhd`,
 *   de la somme de `stts` et de la fin du dernier fragment (`tfdt` + `trun`).
 * - Ogg Opus : le plus haut granule des pages au CRC juste, moins le pré-saut.
 *
 * Garde-fous : un budget de pas par fichier, huit niveaux de boîtes au
 * plus, aucun entier de plus de 8 octets, aucun élément plus long que le
 * reste du fichier ; un `trun` ne compte jamais plus d'échantillons que ses
 * octets n'en portent. Mémoire bornée (troisième vérification) : vingt
 * pistes au plus, les seules déclarées dans Tracks quand il y en a ; rien
 * d'autre ne grandit avec le fichier. Quatrième vérification : le fichier
 * se lit par position (ByteSource), par fenêtre, jamais copié en entier ni
 * boîte par boîte. Rend des secondes, ou null quand le fichier ne se lit pas.
 */
final class MediaDuration
{
    /** Pas de lecture permis par fichier (éléments, boîtes, échantillons, entrées) */
    private const int BUDGET = 400000;

    /** Niveaux de boîtes MP4 au plus */
    private const int MAX_DEPTH = 8;

    /** Blocs consécutifs d'une piste au même temps au plus */
    private const int MAX_SAME_TIME = 8;

    /** Pistes distinctes au plus (un message : un son, une image) */
    private const int MAX_TRACKS = 20;

    private int $budget = self::BUDGET;

    private function __construct(private ByteSource $src) {}

    public static function seconds(string $bytes, string $container): ?float
    {
        return self::measure(ByteSource::fromString($bytes), $container);
    }

    /** Le fichier lu par fenêtre, sans le charger en mémoire */
    public static function secondsOfFile(string $path, string $container): ?float
    {
        $src = ByteSource::fromFile($path);
        if ($src === null) {
            return null;
        }
        try {
            return self::measure($src, $container);
        } finally {
            $src->close();
        }
    }

    private static function measure(ByteSource $src, string $container): ?float
    {
        $reader = new self($src);
        $seconds = match ($container) {
            'webm' => $reader->webm(),
            'mp4' => $reader->mp4(),
            'ogg' => $reader->ogg(),
            default => null,
        };

        return $seconds !== null && is_finite($seconds) && $seconds >= 0 ? $seconds : null;
    }

    /**
     * Un pas de plus ; faux quand le budget du fichier est épuisé (chaque appel le consomme).
     *
     * @phpstan-impure
     */
    private function step(int $n = 1): bool
    {
        $this->budget -= $n;

        return $this->budget >= 0;
    }

    /* ————— WebM (EBML) ————— */

    /** Le DocType de l'en-tête EBML (« webm », « matroska »), quelle que soit la longueur de ses tailles */
    public static function webmDocType(string $head): ?string
    {
        $id = self::vint($head, 0, true);
        $size = $id ? self::vint($head, $id[1], false) : null;
        if ($id === null || $id[0] !== 0x1A45DFA3 || $size === null) {
            return null;
        }
        $offset = $id[1] + $size[1];
        $end = $size[2] ? strlen($head) : min(strlen($head), $offset + $size[0]);
        while ($offset < $end) {
            $child = self::vint($head, $offset, true);
            $length = $child ? self::vint($head, $offset + $child[1], false) : null;
            if ($child === null || $length === null || $length[2]) {
                return null;
            }
            $data = $offset + $child[1] + $length[1];
            if ($child[0] === 0x4282) {
                return $data + $length[0] <= strlen($head) ? substr($head, $data, $length[0]) : null;
            }
            $offset = $data + $length[0];
        }

        return null;
    }

    /** Les éléments où l'on descend : EBML, Segment, Info, Tracks, TrackEntry, Cluster, BlockGroup */
    private const array EBML_MASTERS = [0x1A45DFA3, 0x18538067, 0x1549A966, 0x1654AE6B, 0xAE, 0x1F43B675, 0xA0];

    /** Les seuls éléments simples dont on lit la valeur */
    private const array EBML_READ = [0x2AD7B1, 0x4489, 0xE7, 0xA3, 0xA1, 0xD7];

    private function webm(): ?float
    {
        $length = $this->src->length();
        $offset = 0;
        $scale = 1000000;
        $declared = null;
        $cluster = null;
        $last = null;
        /** @var array<int, array{time: int, same: int}> $tracks les pistes vues (vingt au plus) */
        $tracks = [];
        /** @var array<int, true> $declaredTracks les pistes déclarées dans Tracks (vingt au plus) */
        $declaredTracks = [];

        while ($offset < $length) {
            if (! $this->step()) {
                return null;
            }
            // L'identifiant et la taille tiennent en 16 octets
            $head = $this->src->read($offset, 16);
            $id = self::vint($head, 0, true);
            $size = $id ? self::vint($head, $id[1], false) : null;
            if ($id === null || $size === null) {
                return null;
            }
            $data = $offset + $id[1] + $size[1];

            if (in_array($id[0], self::EBML_MASTERS, true)) {
                // On descend dans l'élément, de taille connue ou non
                $offset = $data;

                continue;
            }
            // Un élément simple de taille inconnue, ou plus long que le reste du fichier : forgé ou tronqué
            if ($size[2] || $size[0] > $length - $data) {
                return null;
            }
            if (! in_array($id[0], self::EBML_READ, true)) {
                $offset = $data + $size[0];

                continue;
            }
            $isBlock = $id[0] === 0xA3 || $id[0] === 0xA1;
            // Un nombre tient sur 8 octets ; d'un bloc, seuls la piste et le temps se lisent (les premiers octets)
            if (! $isBlock && $size[0] > 8) {
                return null;
            }
            $value = $this->src->read($data, $isBlock ? min($size[0], 12) : $size[0]);
            switch ($id[0]) {
                case 0xD7:
                    $number = self::uint($value);
                    if ($number === null || (! isset($declaredTracks[$number]) && count($declaredTracks) >= self::MAX_TRACKS)) {
                        return null;
                    }
                    $declaredTracks[$number] = true;
                    break;
                case 0x2AD7B1:
                    $scale = self::uint($value);
                    if ($scale === null || $scale === 0) {
                        return null;
                    }
                    break;
                case 0x4489:
                    $declared = self::float($value);
                    break;
                case 0xE7:
                    $cluster = self::uint($value);
                    if ($cluster === null) {
                        return null;
                    }
                    break;
                case 0xA3:
                case 0xA1:
                    $block = self::block($value, $cluster);
                    if ($block === null) {
                        return null;
                    }
                    [$track, $time] = $block;
                    // Une piste non déclarée, ou une piste de trop : forgé
                    if (($declaredTracks !== [] && ! isset($declaredTracks[$track])) || (! isset($tracks[$track]) && count($tracks) >= self::MAX_TRACKS)) {
                        return null;
                    }
                    // Une piste dont les blocs ne progressent pas cache sa durée
                    $previous = $tracks[$track] ?? null;
                    $same = $previous !== null && $time <= $previous['time'] ? $previous['same'] + 1 : 0;
                    if ($same > self::MAX_SAME_TIME) {
                        return null;
                    }
                    $tracks[$track] = ['time' => max($time, $previous['time'] ?? $time), 'same' => $same];
                    $last = max($last ?? 0, $time);
                    break;
            }
            $offset = $data + $size[0];
        }

        if ($declared === null && $last === null) {
            return null;
        }

        return max((float) ($declared ?? 0), (float) ($last ?? 0)) * $scale / 1e9;
    }

    /**
     * La piste et le temps d'un bloc : celui de son Cluster plus son temps
     * relatif (entier signé sur 16 bits, après le numéro de piste).
     *
     * @return array{0: int, 1: int}|null
     */
    private static function block(string $block, ?int $cluster): ?array
    {
        $track = self::vint($block, 0, false);
        if ($cluster === null || $track === null || strlen($block) < $track[1] + 2) {
            return null;
        }
        $relative = unpack('n', substr($block, $track[1], 2))[1];
        if ($relative >= 0x8000) {
            $relative -= 0x10000;
        }

        return [$track[0], $cluster + $relative];
    }

    /**
     * Un entier EBML à longueur variable (8 octets au plus) : [valeur, longueur, taille inconnue].
     *
     * @return array{0: int, 1: int, 2: bool}|null
     */
    private static function vint(string $b, int $offset, bool $keepMarker): ?array
    {
        if ($offset >= strlen($b)) {
            return null;
        }
        $first = ord($b[$offset]);
        if ($first === 0) {
            return null;
        }
        $width = 1;
        while (! ($first & (0x80 >> ($width - 1)))) {
            $width++;
        }
        if ($offset + $width > strlen($b)) {
            return null;
        }
        $mask = (0x80 >> ($width - 1)) - 1;
        $value = $keepMarker ? $first : $first & $mask;
        $allOnes = ($first & $mask) === $mask;
        for ($i = 1; $i < $width; $i++) {
            $byte = ord($b[$offset + $i]);
            $allOnes = $allOnes && $byte === 0xFF;
            $value = ($value << 8) | $byte;
        }

        return [$value, $width, ! $keepMarker && $allOnes];
    }

    /** Un entier non signé de 8 octets au plus, sinon null */
    private static function uint(string $value): ?int
    {
        if (strlen($value) > 8 || (strlen($value) === 8 && ord($value[0]) >= 0x80)) {
            return null;
        }
        $n = 0;
        for ($i = 0, $width = strlen($value); $i < $width; $i++) {
            $n = ($n << 8) | ord($value[$i]);
        }

        return $n;
    }

    private static function float(string $value): ?float
    {
        return match (strlen($value)) {
            4 => (float) unpack('G', $value)[1],
            8 => (float) unpack('E', $value)[1],
            default => null,
        };
    }

    /* ————— MP4 (ISO BMFF) ————— */

    /** Les boîtes où l'on descend */
    private const array MP4_CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'moof', 'traf', 'mvex'];

    /** Les seules boîtes simples dont on lit le contenu */
    private const array MP4_READ = ['mvhd', 'tkhd', 'mdhd', 'stts', 'tfhd', 'tfdt', 'trun'];

    private function mp4(): ?float
    {
        $state = ['movie' => null, 'tracks' => [], 'track' => null, 'ends' => [], 'fragment' => null];
        if (! $this->boxes(0, $this->src->length(), $state, 0)) {
            return null;
        }
        $seconds = [];
        if ($state['movie'] !== null) {
            $seconds[] = $state['movie'];
        }
        foreach ($state['tracks'] as $track) {
            if ($track['timescale'] > 0) {
                $seconds[] = max($track['duration'], $track['stts']) / $track['timescale'];
                $seconds[] = ($state['ends'][$track['id']] ?? 0) / $track['timescale'];
            }
        }

        return $seconds === [] ? null : max($seconds);
    }

    /** @param  array<string, mixed>  $state */
    private function boxes(int $start, int $end, array &$state, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }
        $offset = $start;
        while ($offset + 8 <= $end) {
            if (! $this->step()) {
                return false;
            }
            $head = $this->src->read($offset, 16);
            $size = unpack('N', substr($head, 0, 4))[1];
            $type = substr($head, 4, 4);
            $header = 8;
            if ($size === 1) {
                if ($offset + 16 > $end || strlen($head) < 16) {
                    return false;
                }
                $size = unpack('J', substr($head, 8, 8))[1];
                $header = 16;
            } elseif ($size === 0) {
                $size = $end - $offset;
            }
            // Une boîte plus longue que ce qui reste : forgée ou tronquée
            if ($size < $header || $size > $end - $offset) {
                return false;
            }
            $body = $offset + $header;
            $bodyEnd = $offset + $size;

            if (in_array($type, self::MP4_CONTAINERS, true)) {
                if ($type === 'trak') {
                    $state['track'] = ['id' => 0, 'timescale' => 0, 'duration' => 0, 'stts' => 0];
                }
                if (! $this->boxes($body, $bodyEnd, $state, $depth + 1)) {
                    return false;
                }
                if ($type === 'trak' && $state['track'] !== null) {
                    // Vingt pistes au plus
                    if (! isset($state['tracks'][$state['track']['id']]) && count($state['tracks']) >= self::MAX_TRACKS) {
                        return false;
                    }
                    $state['tracks'][$state['track']['id']] = $state['track'];
                    $state['track'] = null;
                }
            } elseif (in_array($type, self::MP4_READ, true) && ! $this->leaf($type, $body, $bodyEnd, $state)) {
                return false;
            }
            $offset = $bodyEnd;
        }

        return true;
    }

    /**
     * Une boîte lue par position, sans la copier : seuls les octets utiles
     * sont lus, et une table (`stts`, `trun`) par morceaux de 64 Ko.
     *
     * @param  array<string, mixed>  $state
     */
    private function leaf(string $type, int $at, int $end, array &$state): bool
    {
        $long = $this->u8($at, $end) === 1;
        switch ($type) {
            case 'mvhd':
                $timescale = $this->u32($at + ($long ? 20 : 12), $end);
                $duration = $long ? $this->u64($at + 24, $end) : $this->u32($at + 16, $end);
                if ($timescale > 0 && $duration !== 0xFFFFFFFF) {
                    $state['movie'] = $duration / $timescale;
                }
                break;
            case 'tkhd':
                if ($state['track'] !== null) {
                    $state['track']['id'] = $this->u32($at + ($long ? 20 : 12), $end);
                }
                break;
            case 'mdhd':
                if ($state['track'] !== null) {
                    $state['track']['timescale'] = $this->u32($at + ($long ? 20 : 12), $end);
                    $duration = $long ? $this->u64($at + 24, $end) : $this->u32($at + 16, $end);
                    $state['track']['duration'] = $duration === 0xFFFFFFFF ? 0 : $duration;
                }
                break;
            case 'stts':
                // La somme des durées des échantillons : la vraie longueur de la piste
                if ($state['track'] !== null) {
                    $entries = min($this->u32($at + 4, $end), intdiv(max(0, $end - $at - 8), 8));
                    if (! $this->step($entries)) {
                        return false;
                    }
                    $total = 0;
                    $this->table($at + 8, $entries, 8, function (array $values) use (&$total): void {
                        for ($i = 0, $n = count($values); $i + 1 < $n; $i += 2) {
                            $total += $values[$i] * $values[$i + 1];
                        }
                    });
                    $state['track']['stts'] += $total;
                }
                break;
            case 'tfhd':
                $flags = $this->u32($at, $end) & 0xFFFFFF;
                $default = 8 + ($flags & 0x01 ? 8 : 0) + ($flags & 0x02 ? 4 : 0);
                $state['fragment'] = [
                    'track' => $this->u32($at + 4, $end),
                    'default' => $flags & 0x08 ? $this->u32($at + $default, $end) : 0,
                    'base' => null,
                ];
                break;
            case 'tfdt':
                if ($state['fragment'] !== null) {
                    $state['fragment']['base'] = $long ? $this->u64($at + 4, $end) : $this->u32($at + 4, $end);
                }
                break;
            case 'trun':
                $fragment = $state['fragment'];
                if ($fragment === null) {
                    break;
                }
                $flags = $this->u32($at, $end) & 0xFFFFFF;
                $count = $this->u32($at + 4, $end);
                $first = 8 + ($flags & 0x01 ? 4 : 0) + ($flags & 0x04 ? 4 : 0);
                $per = ($flags & 0x100 ? 4 : 0) + ($flags & 0x200 ? 4 : 0) + ($flags & 0x400 ? 4 : 0) + ($flags & 0x800 ? 4 : 0);
                if ($per > 0) {
                    // Jamais plus d'échantillons que les octets de la boîte n'en portent
                    $count = min($count, intdiv(max(0, $end - $at - $first), $per));
                }
                if ($flags & 0x100) {
                    if (! $this->step($count)) {
                        return false;
                    }
                    $total = 0;
                    $fields = intdiv($per, 4);
                    $this->table($at + $first, $count, $per, function (array $values) use (&$total, $fields): void {
                        for ($i = 0, $n = count($values); $i < $n; $i += $fields) {
                            $total += $values[$i];
                        }
                    });
                } else {
                    // Sans durée par échantillon : le nombre fois la durée par défaut, sans boucle
                    $total = $count * $fragment['default'];
                }
                $track = $fragment['track'];
                // Des fragments de vingt pistes au plus
                if (! isset($state['ends'][$track]) && count($state['ends']) >= self::MAX_TRACKS) {
                    return false;
                }
                $base = $fragment['base'] ?? ($state['ends'][$track] ?? 0);
                $state['ends'][$track] = max($state['ends'][$track] ?? 0, $base + $total);
                $state['fragment']['base'] = $base + $total;
                break;
        }

        return true;
    }

    /**
     * Une table de `$rows` lignes de `$width` octets, par morceaux d'au plus
     * 64 Ko : `$each` reçoit les entiers sur 32 bits de chaque morceau.
     *
     * @param  \Closure(list<int>): void  $each
     */
    private function table(int $at, int $rows, int $width, \Closure $each): void
    {
        $perChunk = max(1, intdiv(65536, $width));
        for ($row = 0; $row < $rows; $row += $perChunk) {
            $bytes = $this->src->read($at + $row * $width, min($perChunk, $rows - $row) * $width);
            $usable = strlen($bytes) - strlen($bytes) % 4;
            if ($usable > 0) {
                $each(array_values(unpack('N*', substr($bytes, 0, $usable)) ?: []));
            }
        }
    }

    private function u8(int $at, int $end): int
    {
        return $at + 1 <= $end ? ord($this->src->read($at, 1)) : 0;
    }

    private function u32(int $at, int $end): int
    {
        return $at + 4 <= $end ? unpack('N', $this->src->read($at, 4))[1] : 0;
    }

    private function u64(int $at, int $end): int
    {
        return $at + 8 <= $end ? unpack('J', $this->src->read($at, 8))[1] : 0;
    }

    /* ————— Ogg Opus ————— */

    /**
     * Les pages lues dans l'ordre : seules celles au CRC juste comptent, et
     * le plus haut granule fait la durée (une fausse page ajoutée à la fin ne
     * la raccourcit pas).
     */
    private function ogg(): ?float
    {
        $length = $this->src->length();
        $offset = 0;
        $preSkip = null;
        $granule = null;
        $crcBytes = 0;

        while ($offset + 27 <= $length) {
            if (! $this->step()) {
                return null;
            }
            $header = $this->src->read($offset, 27);
            if (substr($header, 0, 4) !== 'OggS') {
                // Des octets hors page : on cherche la suivante
                $next = $this->src->find('OggS', $offset + 1);
                if ($next === null) {
                    break;
                }
                $offset = $next;

                continue;
            }
            $segments = ord($header[26]);
            if ($offset + 27 + $segments > $length) {
                break;
            }
            $bodyLength = array_sum(array_map('ord', str_split($this->src->read($offset + 27, $segments)) ?: []));
            $pageLength = 27 + $segments + $bodyLength;
            if ($segments === 0 || $offset + $pageLength > $length) {
                $offset++;

                continue;
            }
            // Le CRC se calcule au plus deux fois sur la longueur du fichier : des « OggS » forgés en série ne le relancent pas sans fin
            $crcBytes += $pageLength;
            if ($crcBytes > 2 * $length + 65536) {
                return null;
            }
            $page = $this->src->read($offset, $pageLength);
            if (! $this->step() || self::crc(substr_replace($page, "\x00\x00\x00\x00", 22, 4)) !== unpack('V', substr($page, 22, 4))[1]) {
                $offset++;

                continue;
            }
            $body = substr($page, 27 + $segments);
            if ($preSkip === null && str_starts_with($body, 'OpusHead') && strlen($body) >= 12) {
                $preSkip = unpack('v', substr($body, 10, 2))[1];
            }
            $position = unpack('P', substr($page, 6, 8))[1];
            // -1 : aucun paquet ne finit sur cette page
            if ($position >= 0) {
                $granule = max($granule ?? 0, $position);
            }
            $offset += $pageLength;
        }

        if ($preSkip === null || $granule === null) {
            return null;
        }

        return max(0, $granule - $preSkip) / 48000;
    }

    /** @var list<int>|null */
    private static ?array $crcTable = null;

    /** Le CRC des pages Ogg (polynôme 0x04C11DB7, sans réflexion) */
    private static function crc(string $data): int
    {
        if (self::$crcTable === null) {
            self::$crcTable = [];
            for ($i = 0; $i < 256; $i++) {
                $r = $i << 24;
                for ($j = 0; $j < 8; $j++) {
                    $r = $r & 0x80000000 ? (($r << 1) ^ 0x04C11DB7) & 0xFFFFFFFF : ($r << 1) & 0xFFFFFFFF;
                }
                self::$crcTable[] = $r;
            }
        }
        $table = self::$crcTable;
        $crc = 0;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc = (($crc << 8) & 0xFFFFFFFF) ^ $table[(($crc >> 24) ^ ord($data[$i])) & 0xFF];
        }

        return $crc;
    }
}
