<?php

namespace App\Services\Videos;

/**
 * Lit le segment d'initialisation d'une qualité HLS (CMAF, 31.10.2026) :
 * les pistes, leur codec et la taille de l'image, sans jamais lire la
 * vidéo. Lecture bornée : un fichier tronqué ou mal formé rend null.
 */
final class Mp4InitReader
{
    /** Boîtes à ouvrir pour descendre jusqu'aux descriptions de pistes. */
    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl'];

    /** Profondeur maximale : un vrai segment d'initialisation en a six. */
    private const MAX_DEPTH = 8;

    /** @var list<array{handler: ?string, codec: ?string, width: ?int, height: ?int, track_id: ?int, timescale: ?int}> */
    private array $tracks = [];

    /** @var array{handler: ?string, codec: ?string, width: ?int, height: ?int, track_id: ?int, timescale: ?int}|null */
    private ?array $current = null;

    /**
     * @return list<array{handler: ?string, codec: ?string, width: ?int, height: ?int, track_id: ?int, timescale: ?int}>|null null : pas un MP4
     */
    public static function read(string $bytes): ?array
    {
        if (strlen($bytes) < 16 || substr($bytes, 4, 4) !== 'ftyp') {
            return null;
        }
        $reader = new self;

        return $reader->walk($bytes, 0, strlen($bytes), 0) ? $reader->tracks : null;
    }

    private function walk(string $bytes, int $start, int $end, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }
        $pos = $start;
        while ($pos + 8 <= $end) {
            $size = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            $header = 8;
            if ($size === 1) {
                if ($pos + 16 > $end) {
                    return false;
                }
                $size = unpack('J', substr($bytes, $pos + 8, 8))[1];
                $header = 16;
            } elseif ($size === 0) {
                $size = $end - $pos;
            }
            if ($size < $header || $pos + $size > $end) {
                return false;
            }
            $content = $pos + $header;
            $boxEnd = $pos + $size;

            if ($type === 'trak') {
                $this->current = ['handler' => null, 'codec' => null, 'width' => null, 'height' => null, 'track_id' => null, 'timescale' => null];
                if (! $this->walk($bytes, $content, $boxEnd, $depth + 1)) {
                    return false;
                }
                $this->tracks[] = $this->current;
                $this->current = null;
            } elseif (in_array($type, self::CONTAINERS, true)) {
                if (! $this->walk($bytes, $content, $boxEnd, $depth + 1)) {
                    return false;
                }
            } elseif ($type === 'tkhd' && $this->current !== null && $content + 24 <= $boxEnd) {
                // version (1) et drapeaux (3), dates (4 + 4, ou 8 + 8 en version 1), puis le numéro de piste
                $offset = ord($bytes[$content]) === 1 ? 20 : 12;
                $this->current['track_id'] = unpack('N', substr($bytes, $content + $offset, 4))[1];
            } elseif ($type === 'mdhd' && $this->current !== null && $content + 24 <= $boxEnd) {
                // version et drapeaux, dates, puis l'échelle de temps de la piste
                $offset = ord($bytes[$content]) === 1 ? 20 : 12;
                $this->current['timescale'] = unpack('N', substr($bytes, $content + $offset, 4))[1];
            } elseif ($type === 'hdlr' && $this->current !== null && $content + 12 <= $boxEnd) {
                // version et drapeaux (4), pre_defined (4), puis le type de piste
                $this->current['handler'] = substr($bytes, $content + 8, 4);
            } elseif ($type === 'stsd' && $this->current !== null && $content + 16 <= $boxEnd) {
                // version et drapeaux (4), nombre d'entrées (4), puis la première entrée
                $entry = $content + 8;
                $this->current['codec'] = substr($bytes, $entry + 4, 4);
                // Entrée visuelle : 8 octets d'en-tête, 16 réservés, puis largeur et hauteur
                if ($this->current['handler'] === 'vide' && $entry + 36 <= $boxEnd) {
                    $this->current['width'] = unpack('n', substr($bytes, $entry + 32, 2))[1];
                    $this->current['height'] = unpack('n', substr($bytes, $entry + 34, 2))[1];
                }
            }
            $pos = $boxEnd;
        }

        return $pos === $end;
    }

    /**
     * Le temps de début (dans l'échelle de la piste) d'un fragment CMAF, lu dans
     * ses premiers octets : boîte moof, puis la traf de cette piste et son tfdt.
     */
    public static function fragmentStart(string $bytes, int $trackId): ?int
    {
        $pos = 0;
        $end = strlen($bytes);
        while ($pos + 8 <= $end) {
            $size = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            if ($size < 8) {
                return null;
            }
            if ($type === 'moof') {
                return self::trafStart($bytes, $pos + 8, min($end, $pos + $size), $trackId);
            }
            $pos += $size;
        }

        return null;
    }

    private static function trafStart(string $bytes, int $start, int $end, int $trackId): ?int
    {
        $pos = $start;
        while ($pos + 8 <= $end) {
            $size = unpack('N', substr($bytes, $pos, 4))[1];
            if ($size < 8) {
                return null;
            }
            if (substr($bytes, $pos + 4, 4) === 'traf') {
                $track = null;
                $base = null;
                $inner = $pos + 8;
                $trafEnd = min($end, $pos + $size);
                while ($inner + 8 <= $trafEnd) {
                    $innerSize = unpack('N', substr($bytes, $inner, 4))[1];
                    $innerType = substr($bytes, $inner + 4, 4);
                    if ($innerSize < 8) {
                        return null;
                    }
                    if ($innerType === 'tfhd' && $inner + 16 <= $trafEnd) {
                        $track = unpack('N', substr($bytes, $inner + 12, 4))[1];
                    } elseif ($innerType === 'tfdt' && $inner + 16 <= $trafEnd) {
                        $base = ord($bytes[$inner + 8]) === 1 && $inner + 20 <= $trafEnd
                            ? unpack('J', substr($bytes, $inner + 12, 8))[1]
                            : unpack('N', substr($bytes, $inner + 12, 4))[1];
                    }
                    $inner += $innerSize;
                }
                if ($track === $trackId && $base !== null) {
                    return $base;
                }
            }
            $pos += $size;
        }

        return null;
    }
}
