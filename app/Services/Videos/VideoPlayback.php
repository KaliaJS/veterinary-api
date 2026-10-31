<?php

namespace App\Services\Videos;

use App\Models\Video;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * La lecture (31.10.2026) : des listes HLS signées, servies par l'API,
 * dont chaque segment est une adresse signée du bucket. Safari les lit
 * sans jeton : ce sont les signatures qui portent l'accès. L'aperçu
 * gratuit, c'est la même liste coupée après la première minute.
 */
class VideoPlayback
{
    /** Une lecture reste ouverte 8 heures : une vidéo de 3 heures, avec des pauses. */
    public const HOURS = 8;

    public function __construct(private VideoStorage $storage) {}

    /** L'adresse de la liste maîtresse pour cette lecture : 'full' ou 'preview'. */
    public function masterUrl(Video $video, string $scope): string
    {
        return URL::temporarySignedRoute('videos.hls.master', now()->addHours(self::HOURS), ['video' => $video->uuid, 'scope' => $scope]);
    }

    /** La liste maîtresse, réécrite depuis les qualités vérifiées (débits recalculés). */
    public function master(Video $video, string $scope, int $expires): string
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:7', '#EXT-X-INDEPENDENT-SEGMENTS'];
        foreach ($video->renditions ?? [] as $rendition) {
            $lines[] = sprintf('#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d%s',
                max(1, (int) $rendition['bandwidth']), $rendition['width'], $rendition['height'],
                ! empty($rendition['codecs']) ? ',CODECS="'.str_replace('"', '', (string) $rendition['codecs']).'"' : '');
            // Une date, pas un nombre : un nombre se lirait « dans tant de secondes »
            $lines[] = URL::temporarySignedRoute('videos.hls.media', CarbonImmutable::createFromTimestamp($expires), ['video' => $video->uuid, 'name' => $rendition['playlist'], 'scope' => $scope]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Une liste de qualité : chaque segment par une adresse signée du
     * bucket ; pour l'aperçu, rien après la première minute.
     */
    public function media(Video $video, string $name, string $scope, int $expires): ?string
    {
        $rendition = collect($video->renditions ?? [])->firstWhere('playlist', $name);
        if ($rendition === null) {
            return null;
        }
        $text = (string) $this->storage->read($video, $name);
        $until = CarbonImmutable::createFromTimestamp($expires);
        $out = [];
        $elapsed = 0.0;
        $info = null;
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line === '#EXT-X-ENDLIST') {
                continue;
            }
            if (str_starts_with($line, '#EXT-X-MAP:')) {
                $out[] = '#EXT-X-MAP:URI="'.$this->storage->readUrl($video, $rendition['init'], $until).'"';
            } elseif (str_starts_with($line, '#EXTINF:')) {
                $info = $line;
            } elseif (! str_starts_with($line, '#')) {
                // L'aperçu s'arrête après la première minute
                if ($scope === 'preview' && $elapsed >= Video::PREVIEW_SECONDS) {
                    break;
                }
                if ($info === null || ! VideoStorage::isValidName($line)) {
                    continue;
                }
                $out[] = $info;
                $out[] = $this->storage->readUrl($video, $line, $until);
                $elapsed += (float) explode(',', substr($info, strlen('#EXTINF:')))[0];
                $info = null;
            } else {
                $out[] = $line;
            }
        }
        $out[] = '#EXT-X-ENDLIST';

        return implode("\n", $out)."\n";
    }
}
