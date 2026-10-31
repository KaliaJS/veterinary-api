<?php

namespace App\Jobs;

use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\Videos\HlsPackage;
use App\Services\Videos\Sellers;
use App\Services\Videos\VideoStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

    public int $tries = 3;

    public function __construct(public int $videoId) {}

    public function handle(HlsPackage $package, VideoStorage $storage, Sellers $sellers): void
    {
        $video = Video::query()->find($this->videoId);
        if ($video === null || $video->files_status !== VideoFilesStatus::Ready) {
            return;
        }
        $result = $package->inspect($video, [AssembleVideoDownload::FILE]);
        $limits = $sellers->storage($video);
        $overQuota = $result['ok'] && $limits['limit'] < $limits['used'] - (int) $video->size_bytes + $result['size'];

        if ($result['ok'] === false || $overQuota) {
            $storage->deleteAll($video);
            $hasBuyers = $video->purchases()->whereNotNull('paid_at')->exists();
            $video->update([
                'files_status' => VideoFilesStatus::Rejected,
                'rejection_reason' => $result['ok'] === false ? $result['reason'] : 'Votre stockage est plein : la vidéo a été effacée.',
                'status' => $video->status === VideoStatus::Published && $hasBuyers ? VideoStatus::Withdrawn : ($video->status === VideoStatus::Published ? VideoStatus::Draft : $video->status),
                'size_bytes' => 0,
                'renditions' => null,
                'download_path' => null,
            ]);

            return;
        }

        $video->update(['size_bytes' => $result['size'], 'renditions' => $result['renditions'], 'duration_seconds' => $result['duration']]);
    }
}
