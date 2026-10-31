<?php

namespace Tests\Concerns;

/**
 * Fabrique, pour les tests, le paquet HLS que produit le navigateur
 * (Mediabunny, 31.10.2026) : une liste maîtresse, une liste par qualité,
 * un segment d'initialisation CMAF par qualité et des segments.
 */
trait BuildsHlsPackages
{
    private function box(string $type, string $content): string
    {
        return pack('N', 8 + strlen($content)).$type.$content;
    }

    /** Segment d'initialisation : une piste vidéo (codec, largeur, hauteur) et, au choix, une piste de son. */
    protected function initSegment(int $width, int $height, string $videoCodec = 'avc1', ?string $audioCodec = 'mp4a'): string
    {
        $track = function (string $handler, string $entry): string {
            $stsd = $this->box('stsd', pack('N', 0).pack('N', 1).$entry);

            return $this->box('trak', $this->box('tkhd', str_repeat("\0", 84)).$this->box('mdia',
                $this->box('mdhd', str_repeat("\0", 24))
                .$this->box('hdlr', pack('N', 0).pack('N', 0).$handler.str_repeat("\0", 13))
                .$this->box('minf', $this->box('stbl', $stsd))));
        };
        $video = $track('vide', $this->box($videoCodec, str_repeat("\0", 6).pack('n', 1).str_repeat("\0", 16).pack('nn', $width, $height).str_repeat("\0", 50)));
        $audio = $audioCodec === null ? '' : $track('soun', $this->box($audioCodec, str_repeat("\0", 6).pack('n', 1).str_repeat("\0", 20)));

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
                $playlist .= sprintf("#EXTINF:%.3f,\n%s\n", $length, $name);
                // Un fragment CMAF : une boîte moof en tête
                $files[$name] = $this->box('moof', str_repeat(chr(64 + $n), 992));
                $remaining -= $length;
                $i++;
            }
            $files["video-and-audio-{$n}.m3u8"] = $playlist."#EXT-X-ENDLIST\n";
        }
        $files['master.m3u8'] = $master;

        return $files;
    }
}
