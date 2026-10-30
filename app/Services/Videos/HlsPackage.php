<?php

namespace App\Services\Videos;

use App\Models\Video;

/**
 * Le paquet HLS envoyé par le navigateur (31.10.2026) : une liste
 * maîtresse, une liste par qualité (vidéo et son mêlés, jamais de son à
 * part) et des segments CMAF. Contrôlé avant toute mise en vente : H.264,
 * 1080p au plus, une qualité de 720p au plus (celle qui se télécharge),
 * 3 heures au plus, chaque fichier cité présent. Ce qui n'est cité nulle
 * part est effacé.
 */
class HlsPackage
{
    public const MASTER = 'master.m3u8';

    /** Tolérance sur la durée (arrondis des segments). */
    private const DURATION_TOLERANCE = 2.0;

    private const MAX_PLAYLIST_BYTES = 1024 * 1024;

    private const MAX_INIT_BYTES = 1024 * 1024;

    private const MAX_SEGMENT_BYTES = 64 * 1024 * 1024;

    private const MAX_RENDITIONS = 3;

    public function __construct(private VideoStorage $storage) {}

    /**
     * Vérifie le dossier de la vidéo. Rend les qualités et la durée, ou la
     * raison du refus, dite à son vendeur.
     *
     * @return array{ok: true, duration: float, width: int, height: int, size: int, renditions: list<array<string, mixed>>}|array{ok: false, reason: string}
     */
    public function inspect(Video $video): array
    {
        $files = $this->storage->files($video);
        if (! isset($files[self::MASTER])) {
            return $this->refuse('La liste de lecture principale manque : renvoyez la vidéo.');
        }
        if ($files[self::MASTER] > self::MAX_PLAYLIST_BYTES) {
            return $this->refuse('La liste de lecture principale est trop lourde.');
        }

        $variants = $this->parseMaster((string) $this->storage->read($video, self::MASTER));
        if (is_string($variants)) {
            return $this->refuse($variants);
        }

        $referenced = [self::MASTER => true];
        $renditions = [];
        foreach ($variants as $variant) {
            $playlist = $variant['uri'];
            if (! isset($files[$playlist]) || $files[$playlist] > self::MAX_PLAYLIST_BYTES) {
                return $this->refuse('Une liste de lecture manque : renvoyez la vidéo.');
            }
            $media = $this->parseMedia((string) $this->storage->read($video, $playlist));
            if (is_string($media)) {
                return $this->refuse($media);
            }
            $init = $media['init'];
            if (! isset($files[$init]) || $files[$init] > self::MAX_INIT_BYTES) {
                return $this->refuse('Le début d’une qualité manque : renvoyez la vidéo.');
            }
            $tracks = Mp4InitReader::read((string) $this->storage->read($video, $init));
            $picture = $tracks === null ? null : $this->picture($tracks);
            if (is_string($picture) || $picture === null) {
                return $this->refuse($picture ?? 'Ce fichier n’est pas une vidéo MP4 lisible.');
            }
            $bytes = $files[$playlist] + $files[$init];
            foreach ($media['segments'] as $segment) {
                if (! isset($files[$segment['uri']])) {
                    return $this->refuse('Des morceaux de la vidéo manquent : reprenez l’envoi.');
                }
                if ($files[$segment['uri']] > self::MAX_SEGMENT_BYTES) {
                    return $this->refuse('Un morceau de la vidéo est trop lourd.');
                }
                $bytes += $files[$segment['uri']];
                $referenced[$segment['uri']] = true;
            }
            $referenced[$playlist] = true;
            $referenced[$init] = true;
            $renditions[] = [
                'playlist' => $playlist,
                'init' => $init,
                'width' => $picture['width'],
                'height' => $picture['height'],
                'duration' => round($media['duration'], 3),
                'bandwidth' => $media['duration'] > 0 ? (int) ceil($bytes * 8 / $media['duration']) : 0,
                'codecs' => $variant['codecs'],
                'audio' => $picture['audio'],
            ];
        }

        $duration = max(array_column($renditions, 'duration'));
        if ($duration <= 0) {
            return $this->refuse('La vidéo est vide.');
        }
        if ($duration > Video::MAX_DURATION_SECONDS + self::DURATION_TOLERANCE) {
            return $this->refuse('Une vidéo dure 3 heures au plus.');
        }
        foreach ($renditions as $rendition) {
            if (abs($rendition['duration'] - $duration) > self::DURATION_TOLERANCE) {
                return $this->refuse('Les qualités de la vidéo n’ont pas la même durée : renvoyez la vidéo.');
            }
        }

        // La plus légère se télécharge : 720p au plus
        usort($renditions, fn ($a, $b) => $b['height'] * $b['width'] <=> $a['height'] * $a['width']);
        $light = $renditions[count($renditions) - 1];
        if (max($light['width'], $light['height']) > 1280 || min($light['width'], $light['height']) > 720) {
            return $this->refuse('Il manque la version allégée en 720p.');
        }

        // Ce qui n'est cité nulle part n'a rien à faire là
        foreach (array_keys($files) as $name) {
            if (! isset($referenced[$name])) {
                $this->storage->delete($video, $name);
                unset($files[$name]);
            }
        }

        return [
            'ok' => true,
            'duration' => $duration,
            'width' => $renditions[0]['width'],
            'height' => $renditions[0]['height'],
            'size' => array_sum($files),
            'renditions' => $renditions,
        ];
    }

    /**
     * @return list<array{uri: string, codecs: ?string}>|string
     */
    private function parseMaster(string $text): array|string
    {
        $lines = $this->lines($text);
        if (($lines[0] ?? null) !== '#EXTM3U') {
            return 'La liste de lecture principale est illisible.';
        }
        $variants = [];
        $pending = null;
        foreach ($lines as $line) {
            if (str_starts_with($line, '#EXT-X-MEDIA:')) {
                return 'Le son doit être mêlé à chaque qualité : renvoyez la vidéo depuis Veterinary.';
            }
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $pending = $this->attributes(substr($line, strlen('#EXT-X-STREAM-INF:')));

                continue;
            }
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if ($pending === null || ! VideoStorage::isValidName($line) || ! str_ends_with($line, '.m3u8')) {
                return 'La liste de lecture principale cite un fichier inattendu.';
            }
            $variants[] = ['uri' => $line, 'codecs' => $pending['CODECS'] ?? null];
            $pending = null;
        }
        if ($variants === [] || count($variants) > self::MAX_RENDITIONS) {
            return 'La vidéo doit avoir une à trois qualités.';
        }

        return $variants;
    }

    /**
     * @return array{init: string, duration: float, segments: list<array{uri: string, duration: float}>}|string
     */
    private function parseMedia(string $text): array|string
    {
        $lines = $this->lines($text);
        if (($lines[0] ?? null) !== '#EXTM3U') {
            return 'Une liste de lecture est illisible.';
        }
        $init = null;
        $segments = [];
        $duration = 0.0;
        $pending = null;
        $ended = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, '#EXT-X-MAP:')) {
                $uri = $this->attributes(substr($line, strlen('#EXT-X-MAP:')))['URI'] ?? null;
                if ($init !== null || $uri === null || ! VideoStorage::isValidName($uri)) {
                    return 'Une liste de lecture cite un début inattendu.';
                }
                $init = $uri;
            } elseif (str_starts_with($line, '#EXTINF:')) {
                $value = explode(',', substr($line, strlen('#EXTINF:')))[0];
                if (! is_numeric($value) || (float) $value < 0 || (float) $value > 60) {
                    return 'Une liste de lecture donne une durée inattendue.';
                }
                $pending = (float) $value;
            } elseif ($line === '#EXT-X-ENDLIST') {
                $ended = true;
            } elseif ($line !== '' && ! str_starts_with($line, '#')) {
                if ($pending === null || ! VideoStorage::isValidName($line) || ! str_ends_with($line, '.m4s')) {
                    return 'Une liste de lecture cite un morceau inattendu.';
                }
                $segments[] = ['uri' => $line, 'duration' => $pending];
                $duration += $pending;
                $pending = null;
            }
        }
        if ($init === null || $segments === [] || ! $ended) {
            return 'Une liste de lecture est incomplète : reprenez l’envoi.';
        }

        return ['init' => $init, 'duration' => $duration, 'segments' => $segments];
    }

    /**
     * Une piste vidéo H.264 de 1080p au plus, et au plus une piste de son AAC.
     *
     * @param  list<array{handler: ?string, codec: ?string, width: ?int, height: ?int}>  $tracks
     * @return array{width: int, height: int, audio: bool}|string
     */
    private function picture(array $tracks): array|string
    {
        $video = array_values(array_filter($tracks, fn ($t) => $t['handler'] === 'vide'));
        $audio = array_values(array_filter($tracks, fn ($t) => $t['handler'] === 'soun'));
        if (count($video) !== 1 || count($audio) > 1 || count($tracks) !== count($video) + count($audio)) {
            return 'Chaque qualité doit avoir une image et au plus un son.';
        }
        if (! in_array($video[0]['codec'], ['avc1', 'avc3'], true)) {
            return 'La vidéo doit être en H.264 : renvoyez-la depuis Veterinary.';
        }
        if ($audio !== [] && $audio[0]['codec'] !== 'mp4a') {
            return 'Le son doit être en AAC : renvoyez la vidéo depuis Veterinary.';
        }
        $width = (int) $video[0]['width'];
        $height = (int) $video[0]['height'];
        if ($width < 16 || $height < 16) {
            return 'La taille de l’image est illisible.';
        }
        if (max($width, $height) > 1920 || min($width, $height) > 1080) {
            return 'Une vidéo est en 1080p au plus.';
        }

        return ['width' => $width, 'height' => $height, 'audio' => $audio !== []];
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        return array_map('trim', preg_split('/\r\n|\n|\r/', $text) ?: []);
    }

    /** @return array<string, string> */
    private function attributes(string $list): array
    {
        preg_match_all('/([A-Z0-9-]+)=("[^"]*"|[^,]*)/', $list, $matches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as [, $key, $value]) {
            $attributes[$key] = trim($value, '"');
        }

        return $attributes;
    }

    /** @return array{ok: false, reason: string} */
    private function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
