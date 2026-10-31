<?php

namespace App\Services\Videos;

use App\Jobs\PurgeVideoFolder;
use App\Models\Video;
use App\Services\Photos\ImageReencodee;
use GdImage;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * L'affiche d'une vidéo de formation (31.10.2026) : une image tirée de la
 * vidéo par le navigateur du vendeur, ou une photo. Comme les photos des
 * cliniques, elle n'est jamais gardée telle quelle : recadrée en 16:9 au
 * centre, réécrite en WebP à trois largeurs, sans rien de l'appareil
 * (EXIF, position GPS), dans un dossier au nom imprévisible. Hors du
 * dossier de la vidéo : un nouvel envoi efface ce dossier, l'affiche reste.
 */
final class VideoPosters
{
    /** Largeurs servies : vignette d'une rangée, affiche ouverte, fond d'une vidéo ouverte. */
    public const array WIDTHS = [640, 1280, 1920];

    public const int QUALITY = 80;

    public const int PREVIEW_WIDTH = 32;

    public const int PREVIEW_QUALITY = 30;

    /** Plus petite image acceptée : en dessous, l'affiche serait floue à l'écran. */
    public const int MIN_WIDTH = 640;

    public const int MIN_HEIGHT = 360;

    /** Le jeton du dossier d'une affiche. */
    public const string TOKEN = '[A-Za-z0-9]{40}';

    private const string DISK = 'prive';

    /**
     * Range l'affiche et ses versions.
     *
     * @return array{token: string, preview: string}
     */
    public function store(UploadedFile $file): array
    {
        $image = self::crop(ImageReencodee::lire($file));
        // Jugée redressée et recadrée (vérification du 31.10.2026) : une photo tournée par ses données EXIF
        // n'a plus les dimensions qu'elle annonce, et c'est l'affiche rangée qui doit rester nette
        if (imagesx($image) < self::MIN_WIDTH || imagesy($image) < self::MIN_HEIGHT) {
            throw ValidationException::withMessages(['poster' => self::tooSmall()]);
        }
        $token = Str::random(40);
        foreach (self::WIDTHS as $width) {
            Storage::disk(self::DISK)->put(self::path($token, $width), ImageReencodee::webp(ImageReencodee::reduire($image, $width), self::QUALITY));
        }
        $preview = 'data:image/webp;base64,'.base64_encode(ImageReencodee::webp(ImageReencodee::reduire($image, self::PREVIEW_WIDTH), self::PREVIEW_QUALITY));

        return ['token' => $token, 'preview' => $preview];
    }

    public static function tooSmall(): string
    {
        return 'L’affiche doit mesurer au moins '.self::MIN_WIDTH.' × '.self::MIN_HEIGHT.' pixels.';
    }

    /**
     * Un seul geste à la fois sur l'affiche d'une vidéo (envoyer, retirer,
     * effacer le brouillon) : deux onglets qui se croisent ne laissent
     * jamais un dossier sans vidéo sur le disque.
     */
    public static function lock(int $videoId): Lock
    {
        return Cache::lock('video-poster-'.$videoId, 60);
    }

    /** Efface son dossier, une fois la transaction conclue, par la file qui réessaie. */
    public function delete(?string $token): void
    {
        if ($token !== null) {
            PurgeVideoFolder::dispatch(self::folder($token))->afterCommit();
        }
    }

    public static function folder(string $token): string
    {
        return 'video-posters/'.$token;
    }

    /** La version d'une largeur. */
    public static function path(string $token, int $width): string
    {
        return self::folder($token).'/'.$width.'.webp';
    }

    /**
     * Ce que l'écran affiche : la version moyenne, toutes les largeurs pour
     * `srcset`, et l'aperçu flou.
     *
     * @return array{url: string, srcset: string, preview: ?string}|null
     */
    public static function for(Video $video): ?array
    {
        if ($video->poster_token === null) {
            return null;
        }
        $url = fn (int $width): string => route('videos.poster', ['token' => $video->poster_token, 'width' => $width]);

        return [
            'url' => $url(1280),
            'srcset' => implode(', ', array_map(fn (int $width): string => $url($width).' '.$width.'w', self::WIDTHS)),
            'preview' => $video->poster_preview,
        ];
    }

    /** Recadrée en 16:9 au centre : ce qui est rangé est ce que l'écran montre. */
    private static function crop(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $targetWidth = min($width, (int) round($height * 16 / 9));
        $targetHeight = min($height, (int) round($targetWidth * 9 / 16));
        if ($targetWidth === $width && $targetHeight === $height) {
            return $image;
        }

        return imagecrop($image, [
            'x' => intdiv($width - $targetWidth, 2),
            'y' => intdiv($height - $targetHeight, 2),
            'width' => $targetWidth,
            'height' => $targetHeight,
        ]) ?: $image;
    }
}
