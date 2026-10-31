<?php

namespace App\Console\Commands;

use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\Videos\VideoStorage;
use Illuminate\Console\Command;
use Throwable;

/**
 * Les envois abandonnés (31.10.2026) : un brouillon resté en cours d'envoi
 * plus de 24 heures perd ses fichiers. Le brouillon reste, son vendeur
 * peut renvoyer la vidéo.
 */
class PurgeAbandonedVideoUploads extends Command
{
    protected $signature = 'videos:purge-abandoned-uploads';

    protected $description = 'Efface les fichiers des envois de vidéos abandonnés depuis plus de 24 heures';

    public function handle(VideoStorage $storage): int
    {
        Video::query()
            ->where('status', VideoStatus::Draft->value)
            ->where(fn ($abandoned) => $abandoned
                // Un envoi arrêté depuis un jour, ou commencé depuis plus de 3 jours (même s'il redemande des adresses)
                ->where(fn ($pending) => $pending->where('files_status', VideoFilesStatus::Pending->value)
                    ->where(fn ($old) => $old->where('updated_at', '<', now()->subDay())->orWhere('upload_started_at', '<', now()->subDays(3))))
                // Des fichiers déposés après un refus, par des adresses encore valables
                ->orWhere(fn ($rejected) => $rejected->where('files_status', VideoFilesStatus::Rejected->value)->where('updated_at', '<', now()->subDay())))
            ->chunkById(100, function ($videos) use ($storage): void {
                foreach ($videos as $video) {
                    try {
                        if ($storage->files($video) !== []) {
                            $storage->deleteAll($video);
                        }
                        $video->forceFill(['size_bytes' => 0, 'upload_started_at' => null])->saveQuietly();
                    } catch (Throwable $e) {
                        // Une panne du stockage : la vidéo attend la nuit suivante, les autres passent
                        report($e);
                    }
                }
            });

        return self::SUCCESS;
    }
}
