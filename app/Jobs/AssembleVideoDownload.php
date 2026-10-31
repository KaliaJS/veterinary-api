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
use Illuminate\Support\Facades\DB;
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

    public int $tries = 4;

    /** Le stockage ne répond pas : on réessaie dans 1, 5 puis 30 minutes (relecture et page de l'acheteur relancent ensuite) */
    public array $backoff = [60, 300, 1800];

    /** Secondes d'attente du verrou des fichiers (une relecture en cours) */
    public int $lockWait = 600;

    /** @param  int|null  $upload  l'envoi assemblé (heure de départ) : jamais écrit sur un envoi plus récent */
    public function __construct(public int $videoId, public ?int $upload = null) {}

    public function uniqueId(): string
    {
        return $this->videoId.'-'.($this->upload ?? 0);
    }

    public function handle(VideoStorage $storage): void
    {
        // Jamais en même temps que la relecture : chacune lit puis écrit la taille de la vidéo
        Video::filesLock($this->videoId)->block($this->lockWait, fn () => $this->assemble($storage));
    }

    private function assemble(VideoStorage $storage): void
    {
        $video = Video::query()->find($this->videoId);
        if ($video === null || $video->files_status !== VideoFilesStatus::Ready || $video->renditions === null
            || ($this->upload !== null && $video->uploadStamp() !== $this->upload)) {
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

        // Seulement sur l'envoi assemblé, toujours prêt ; la taille ajoutée d'un seul coup, sans relire l'ancienne
        $written = Video::query()->whereKey($video->id)
            ->where('files_status', VideoFilesStatus::Ready->value)
            ->where('upload_started_at', $video->upload_started_at)
            ->whereNull('download_path')
            ->update(['download_path' => self::FILE, 'size_bytes' => DB::raw('size_bytes + '.$size)]);
        // Un nouvel envoi a commencé entre-temps : ce fichier n'est pas le sien
        if ($written === 0 && Video::query()->whereKey($video->id)->value('download_path') === null) {
            $storage->delete($video, self::FILE);
        }
    }
}
