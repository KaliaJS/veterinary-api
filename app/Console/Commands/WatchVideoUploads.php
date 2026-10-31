<?php

namespace App\Console\Commands;

use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\Videos\Sellers;
use App\Services\Videos\VideoStorage;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Les envois en cours, relus toutes les 10 minutes (31.10.2026, quatrième
 * vérification) : une adresse d'envoi ne fixe pas la taille du fichier (le
 * stockage ne le permet pas). Un envoi qui dépasse sa place annoncée (avec
 * une marge) ou le stockage du vendeur est arrêté et effacé, sans attendre
 * la purge de la nuit.
 */
class WatchVideoUploads extends Command
{
    protected $signature = 'videos:watch-uploads';

    protected $description = 'Arrête les envois de vidéos qui dépassent leur place annoncée';

    /** Marge au-delà de la place annoncée (la même qu'à la demande d'adresses) */
    public const MARGIN = 50_000_000;

    public function handle(VideoStorage $storage, Sellers $sellers): int
    {
        Video::query()
            ->where('status', VideoStatus::Draft->value)
            ->where('files_status', VideoFilesStatus::Pending->value)
            ->whereNotNull('upload_started_at')
            ->where('upload_started_at', '>', now()->subDays(3))
            ->chunkById(100, function ($videos) use ($storage, $sellers): void {
                foreach ($videos as $video) {
                    try {
                        $deposited = array_sum($storage->files($video));
                        $limits = $sellers->storage($video);
                        $announced = (int) ($video->upload_expected_bytes ?? 0);
                        if ($deposited <= $announced * 1.5 + self::MARGIN && $limits['limit'] >= $limits['used'] - $video->storageBytes() + $deposited) {
                            continue;
                        }
                        report(new RuntimeException("Envoi de la vidéo {$video->id} au-delà de sa place : {$deposited} octets pour {$announced} annoncés"));
                        $storage->deleteAll($video);
                        $video->update([
                            'files_status' => VideoFilesStatus::Rejected,
                            'rejection_reason' => 'La vidéo dépasse la place annoncée ou votre stockage : recommencez l’envoi.',
                            'size_bytes' => 0, 'upload_started_at' => null,
                        ]);
                    } catch (Throwable $e) {
                        // Le stockage ne répond pas : la vidéo attend le passage suivant
                        report($e);
                    }
                }
            });

        return self::SUCCESS;
    }
}
