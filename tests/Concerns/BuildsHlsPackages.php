<?php

namespace Tests\Concerns;

/**
 * Fabrique, pour les tests, le paquet HLS que produit le navigateur
 * (Mediabunny, 31.10.2026) : une liste maîtresse, une liste par qualité,
 * un segment d'initialisation CMAF par qualité et des segments.
 */
trait BuildsHlsPackages
{
    /** Échelle de temps de la piste vidéo des paquets d'essai */
    private const VIDEO_TIMESCALE = 90000;

    /** Un fragment CMAF : styp, puis moof avec une traf par piste (tfhd, tfdt), puis les données */
    protected function fragment(float $start, string $payload): string
    {
        $traf = fn (int $track, int $base) => $this->box('traf',
            $this->box('tfhd', pack('N', 0).pack('N', $track)).$this->box('tfdt', pack('N', 1 << 24).pack('J', $base)));
        // Comme Mediabunny : les temps partent de 10 secondes
        $moof = $this->box('moof', $this->box('mfhd', pack('N', 0).pack('N', 1))
            .$traf(1, (int) round((10 + $start) * self::VIDEO_TIMESCALE)).$traf(2, (int) round((10 + $start) * 48000)));

        return $this->box('styp', 'cmfs'.pack('N', 0)).$moof.$this->box('mdat', $payload);
    }

    private function box(string $type, string $content): string
    {
        return pack('N', 8 + strlen($content)).$type.$content;
    }

    /** Segment d'initialisation : une piste vidéo (codec, largeur, hauteur) et, au choix, une piste de son. */
    protected function initSegment(int $width, int $height, string $videoCodec = 'avc1', ?string $audioCodec = 'mp4a'): string
    {
        $track = function (string $handler, string $entry, int $id, int $timescale): string {
            $stsd = $this->box('stsd', pack('N', 0).pack('N', 1).$entry);
            // tkhd : version et drapeaux, deux dates, puis le numéro de piste ; mdhd : deux dates, puis l'échelle de temps
            $tkhd = pack('N', 0).pack('N', 0).pack('N', 0).pack('N', $id).str_repeat("\0", 68);
            $mdhd = pack('N', 0).pack('N', 0).pack('N', 0).pack('N', $timescale).str_repeat("\0", 8);

            return $this->box('trak', $this->box('tkhd', $tkhd).$this->box('mdia',
                $this->box('mdhd', $mdhd)
                .$this->box('hdlr', pack('N', 0).pack('N', 0).$handler.str_repeat("\0", 13))
                .$this->box('minf', $this->box('stbl', $stsd))));
        };
        $video = $track('vide', $this->box($videoCodec, str_repeat("\0", 6).pack('n', 1).str_repeat("\0", 16).pack('nn', $width, $height).str_repeat("\0", 50)), 1, self::VIDEO_TIMESCALE);
        $audio = $audioCodec === null ? '' : $track('soun', $this->box($audioCodec, str_repeat("\0", 6).pack('n', 1).str_repeat("\0", 20)), 2, 48000);

        return $this->box('ftyp', 'iso6'.pack('N', 0).'iso6cmfc').$this->box('moov', $this->box('mvhd', str_repeat("\0", 100)).$video.$audio.$this->box('mvex', ''));
    }

    /**
     * Le paquet complet : nom => contenu.
     *
     * @param  list<array{0: int, 1: int}>  $sizes  qualités (largeur, hauteur)
     * @return array<string, string>
     */
    protected function hlsPackage(float $seconds = 30, array $sizes = [[1920, 1080], [1280, 720]], string $videoCodec = 'avc1', float $segment = 6): array
    {
        $files = [];
        $master = "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-INDEPENDENT-SEGMENTS\n";
        foreach ($sizes as $index => [$width, $height]) {
            $n = $index + 1;
            $master .= "#EXT-X-STREAM-INF:BANDWIDTH=0,CODECS=\"avc1.640028,mp4a.40.2\",RESOLUTION={$width}x{$height}\nvideo-and-audio-{$n}.m3u8\n";
            $files["init-{$n}.mp4"] = $this->initSegment($width, $height, $videoCodec);
            $playlist = "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-TARGETDURATION:".(int) ceil($segment)."\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-PLAYLIST-TYPE:VOD\n#EXT-X-MAP:URI=\"init-{$n}.mp4\"\n";
            $remaining = $seconds;
            $i = 1;
            while ($remaining > 0.0001) {
                $length = min($segment, $remaining);
                $name = sprintf('segment-%d-%05d.m4s', $n, $i);
                $playlist .= sprintf("#EXTINF:%.3F,\n%s\n", $length, $name);
                $files[$name] = $this->fragment($seconds - $remaining, str_repeat(chr(64 + $n), 900));
                $remaining -= $length;
                $i++;
            }
            $files["video-and-audio-{$n}.m3u8"] = $playlist."#EXT-X-ENDLIST\n";
        }
        $files['master.m3u8'] = $master;

        return $files;
    }
}
