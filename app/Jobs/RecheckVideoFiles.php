<?php

namespace App\Jobs;

use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\Videos\HlsPackage;
use App\Services\Videos\VideoStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * La relecture d'une vidéo contrôlée (31.10.2026, après la vérification
 * de l'API) : les adresses d'envoi données avant le contrôle écrivent
 * encore 15 minutes. Une fois toutes expirées, on relit le paquet : ce
 * qui a été remplacé ou ajouté depuis est recontrôlé, recompté dans le
 * stockage, et un paquet devenu non conforme est retiré et effacé.
 */
class RecheckVideoFiles implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    /** Une panne du stockage : on réessaie dans 5, 15 puis 45 minutes */
    public array $backoff = [300, 900, 2700];

    /** Ce que la vidéo peut peser de plus qu'au contrôle sans qu'on y voie un ajout (arrondis, listes) */
    private const GROWTH_TOLERANCE = 1_000_000;

    public function __construct(public int $videoId) {}

    /**
     * Relue nulle part après tous les essais : on ne vend pas ce qu'on n'a pas
     * pu relire. Sans acheteur, elle repart en brouillon refusé (la purge en
     * efface les fichiers) ; achetée, elle quitte seulement la vente.
     */
    public function failed(?Throwable $exception): void
    {
        $video = Video::query()->find($this->videoId);
        if ($video === null || $video->files_status !== VideoFilesStatus::Ready) {
            return;
        }
        if ($video->purchases()->whereNotNull('paid_at')->exists()) {
            if ($video->status === VideoStatus::Published) {
                $video->update(['status' => VideoStatus::Withdrawn, 'withdrawn_at' => now()]);
            }

            return;
        }
        $video->update([
            'files_status' => VideoFilesStatus::Rejected,
            'rejection_reason' => 'La vidéo n’a pas pu être relue après l’envoi : renvoyez-la.',
            'status' => $video->status === VideoStatus::Published ? VideoStatus::Draft : $video->status,
        ]);
    }

    public function handle(HlsPackage $package, VideoStorage $storage): void
    {
        $video = Video::query()->find($this->videoId);
        if ($video === null || $video->files_status !== VideoFilesStatus::Ready) {
            return;
        }
        $result = $package->inspect($video, [AssembleVideoDownload::FILE]);
        if ($result['ok'] === false && ! empty($result['transient'])) {
            // Rien n'est jugé sur une panne : la file réessaiera
            throw new RuntimeException('Stockage injoignable pendant la relecture de la vidéo '.$video->id);
        }
        // Plus lourde qu'au contrôle : des fichiers ont été remplacés ou ajoutés depuis
        $grown = $result['ok'] && $result['size'] > (int) $video->size_bytes + self::GROWTH_TOLERANCE;
        if ($result['ok'] && ! $grown) {
            $video->update(['size_bytes' => $result['size'], 'renditions' => $result['renditions'], 'duration_seconds' => $result['duration']]);

            return;
        }
        $reason = $result['ok'] ? 'Des fichiers ont été ajoutés après le contrôle : renvoyez la vidéo.' : $result['reason'];

        // Achetée entre-temps : jamais effacée, ceux qui l'ont payée la gardent ; elle quitte seulement la vente
        if ($video->purchases()->whereNotNull('paid_at')->exists()) {
            report(new RuntimeException("Vidéo {$video->id} modifiée après le contrôle, déjà achetée : retirée de la vente ({$reason})"));
            if ($video->status === VideoStatus::Published) {
                $video->update(['status' => VideoStatus::Withdrawn, 'withdrawn_at' => now()]);
            }

            return;
        }

        $storage->deleteAll($video);
        $video->update([
            'files_status' => VideoFilesStatus::Rejected,
            'rejection_reason' => $reason,
            'status' => $video->status === VideoStatus::Published ? VideoStatus::Draft : $video->status,
            'size_bytes' => 0,
            'renditions' => null,
            'download_path' => null,
        ]);
    }
}
