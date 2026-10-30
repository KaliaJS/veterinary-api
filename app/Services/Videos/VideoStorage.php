<?php

namespace App\Services\Videos;

use App\Models\Video;
use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use League\Flysystem\FileAttributes;

/**
 * Les fichiers des vidéos dans le bucket privé (31.10.2026). Le navigateur
 * envoie et lit directement, par des adresses signées qui ne permettent
 * qu'une opération sur un fichier choisi par le serveur. Sur le disque
 * local (développement, tests), deux routes signées tiennent ce rôle :
 * le disque privé, lui, ne sert jamais rien.
 */
class VideoStorage
{
    /** Un nom de fichier du dossier d'une vidéo : jamais de chemin. */
    public const FILE_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/';

    /** Fichiers qu'un navigateur peut envoyer : listes HLS et segments CMAF. */
    public const UPLOADABLE = '/\.(m3u8|mp4|m4s)$/';

    public function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk('prive');
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::FILE_NAME, $name) === 1 && ! str_contains($name, '..');
    }

    /** L'adresse pour envoyer un fichier, valable 15 minutes, pour ce seul fichier. */
    public function uploadUrl(Video $video, string $name, DateTimeInterface $expires): string
    {
        $path = $video->folder().'/'.$name;
        if ($this->isS3()) {
            return $this->disk()->temporaryUploadUrl($path, $expires, ['ContentType' => self::contentType($name)])['url'];
        }

        return URL::temporarySignedRoute('videos.local-upload', $expires, ['video' => $video->uuid, 'name' => $name, 'upload' => 1]);
    }

    /** L'adresse pour lire un fichier, signée jusqu'à l'échéance. */
    public function readUrl(Video $video, string $name, DateTimeInterface $expires, ?string $downloadAs = null): string
    {
        $path = $video->folder().'/'.$name;
        if ($this->isS3()) {
            return $this->disk()->temporaryUrl($path, $expires, array_filter([
                'ResponseContentType' => self::contentType($name),
                'ResponseContentDisposition' => $downloadAs ? 'attachment; filename="'.$downloadAs.'"' : null,
            ]));
        }

        return URL::temporarySignedRoute('videos.local-file', $expires, array_filter(['video' => $video->uuid, 'name' => $name, 'as' => $downloadAs]));
    }

    private function isS3(): bool
    {
        return ($this->disk()->getConfig()['driver'] ?? null) === 's3';
    }

    /**
     * Les fichiers du dossier de la vidéo et leur taille.
     *
     * @return array<string, int> nom => octets
     */
    public function files(Video $video): array
    {
        $files = [];
        foreach ($this->disk()->getDriver()->listContents($video->folder(), false) as $item) {
            if ($item instanceof FileAttributes) {
                $files[basename($item->path())] = (int) ($item->fileSize() ?? $this->disk()->size($item->path()));
            }
        }
        ksort($files);

        return $files;
    }

    public function read(Video $video, string $name): ?string
    {
        return $this->disk()->get($video->folder().'/'.$name);
    }

    public function delete(Video $video, string $name): void
    {
        $this->disk()->delete($video->folder().'/'.$name);
    }

    public function deleteAll(Video $video): void
    {
        $this->disk()->deleteDirectory($video->folder());
    }

    public static function contentType(string $name): string
    {
        return match (pathinfo($name, PATHINFO_EXTENSION)) {
            'm3u8' => 'application/vnd.apple.mpegurl',
            'm4s' => 'video/iso.segment',
            default => 'video/mp4',
        };
    }
}
