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

    /** @var list<array{handler: ?string, codec: ?string, width: ?int, height: ?int}> */
    private array $tracks = [];

    /** @var array{handler: ?string, codec: ?string, width: ?int, height: ?int}|null */
    private ?array $current = null;

    /**
     * @return list<array{handler: ?string, codec: ?string, width: ?int, height: ?int}>|null null : pas un MP4
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
                $this->current = ['handler' => null, 'codec' => null, 'width' => null, 'height' => null];
                if (! $this->walk($bytes, $content, $boxEnd, $depth + 1)) {
                    return false;
                }
                $this->tracks[] = $this->current;
                $this->current = null;
            } elseif (in_array($type, self::CONTAINERS, true)) {
                if (! $this->walk($bytes, $content, $boxEnd, $depth + 1)) {
                    return false;
                }
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
}
