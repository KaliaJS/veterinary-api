<?php

namespace App\Jobs;

use App\Enums\VideoFilesStatus;
use App\Models\Video;
use App\Services\Videos\VideoStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * La version à télécharger (31.10.2026) : la qualité allégée (720p au
 * plus), mise bout à bout en un MP4. Les segments CMAF qui suivent leur
 * segment d'initialisation forment un MP4 fragmenté lisible tel quel :
 * aucune conversion, aucun ffmpeg.
 */
class AssembleVideoDownload implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const FILE = 'download.mp4';

    public int $tries = 3;

    /** Le stockage ne répond pas : on réessaie dans 1, puis 5 minutes (et la relecture suivante relance) */
    public array $backoff = [60, 300];

    public function __construct(public int $videoId) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    public function handle(VideoStorage $storage): void
    {
        $video = Video::query()->find($this->videoId);
        if ($video === null || $video->files_status !== VideoFilesStatus::Ready || $video->renditions === null) {
            return;
        }
        // La plus légère : la dernière, les qualités sont rangées de la plus grande à la plus petite
        $light = $video->renditions[count($video->renditions) - 1];
        $names = [$light['init']];
        // Une liste illisible ferait un fichier vide, servi pour toujours : on réessaie plutôt
        $playlist = $storage->read($video, $light['playlist']);
        if ($playlist === null || $playlist === '') {
            throw new RuntimeException('Liste de la version légère illisible pour la vidéo '.$video->id);
        }
        foreach (preg_split('/\r\n|\n|\r/', $playlist) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#') && VideoStorage::isValidName($line)) {
                $names[] = $line;
            }
        }
        if (count($names) < 2) {
            throw new RuntimeException('Aucun morceau dans la version légère de la vidéo '.$video->id);
        }
        $size = $storage->concatenate($video, $names, self::FILE);

        $video->forceFill([
            'download_path' => self::FILE,
            'size_bytes' => (int) $video->size_bytes + $size,
        ])->save();
    }
}
