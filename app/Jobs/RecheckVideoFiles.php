<?php

namespace App\Jobs;

use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Events\VideoUpdated;
use App\Models\Video;
use App\Services\Videos\HlsPackage;
use App\Services\Videos\VideoPublisher;
use App\Services\Videos\VideoStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * La relecture d'une vidéo contrôlée (31.10.2026, après la vérification
 * de l'API) : les adresses d'envoi données avant le contrôle écrivent
 * encore 15 minutes. Une fois toutes expirées, on relit le paquet : ce
 * qui a été remplacé ou ajouté depuis est recontrôlé, recompté dans le
 * stockage, et un paquet devenu non conforme est retiré et effacé.
 *
 * Une seule relecture à la fois par vidéo et par passage (tout de suite,
 * puis après l'expiration des adresses) ; une mise en vente demandée
 * pendant la relecture se fait à la fin, d'elle-même (cinquième
 * vérification).
 */
class RecheckVideoFiles implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    /** Trois heures de vidéo, chaque morceau relu en entier : largement de quoi faire */
    public int $timeout = 900;

    /** Le verrou tombe de lui-même si une relecture meurt sans le rendre */
    public int $uniqueFor = 3600;

    /** Une panne du stockage : on réessaie dans 5, 15 puis 45 minutes */
    public array $backoff = [300, 900, 2700];

    /** Ce que la vidéo peut peser de plus qu'au contrôle sans qu'on y voie un ajout (arrondis, listes) */
    private const GROWTH_TOLERANCE = 1_000_000;

    /** @param  string  $pass  « now » au contrôle et à la mise en vente, « late » après l'expiration des adresses */
    public function __construct(public int $videoId, public string $pass = 'now') {}

    public function uniqueId(): string
    {
        return $this->videoId.'-'.$this->pass;
    }

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
        // Une panne longue n'est pas un mauvais fichier : rien ne s'efface, la vidéo quitte la vente
        // le temps d'être relue (la mise en vente relance la relecture)
        report(new RuntimeException("Vidéo {$video->id} pas relue après tous les essais : hors de la vente en attendant", 0, $exception));
        $withdraw = $video->status === VideoStatus::Published && $video->hasBuyers();
        $video->update([
            'files_checked_at' => null,
            'publish_requested_at' => null,
            'status' => $video->status === VideoStatus::Published ? ($withdraw ? VideoStatus::Withdrawn : VideoStatus::Draft) : $video->status,
            'withdrawn_at' => $withdraw ? now() : $video->withdrawn_at,
        ]);
        VideoUpdated::announce($video);
    }

    public function handle(HlsPackage $package, VideoStorage $storage, VideoPublisher $publisher): void
    {
        $video = Video::query()->find($this->videoId);
        if ($video === null || $video->files_status !== VideoFilesStatus::Ready) {
            return;
        }
        $this->check($video, $package, $storage, $publisher);
        // Relue, mise en vente, retirée ou refusée : l'écran du vendeur suit
        VideoUpdated::announce($video->refresh());
    }

    private function check(Video $video, HlsPackage $package, VideoStorage $storage, VideoPublisher $publisher): void
    {
        $result = $package->inspect($video, [AssembleVideoDownload::FILE], everySegment: true);
        if ($result['ok'] === false && ! empty($result['transient'])) {
            // Rien n'est jugé sur une panne : la file réessaiera
            throw new RuntimeException('Stockage injoignable pendant la relecture de la vidéo '.$video->id);
        }
        // Plus lourde qu'au contrôle : des fichiers ont été remplacés ou ajoutés depuis
        $grown = $result['ok'] && $result['size'] > (int) $video->size_bytes + self::GROWTH_TOLERANCE;
        if ($result['ok'] && ! $grown) {
            $video->update(['size_bytes' => $result['size'], 'renditions' => $result['renditions'], 'duration_seconds' => $result['duration'], 'files_checked_at' => now()]);
            $this->publishIfRequested($video->refresh(), $publisher);

            return;
        }
        $reason = $result['ok'] ? 'Des fichiers ont été ajoutés après le contrôle : renvoyez la vidéo.' : $result['reason'];

        // Achetée entre-temps : jamais effacée, ceux qui l'ont payée la gardent ; elle quitte seulement la vente
        // La remettre en vente relance une relecture complète : rien ne se revend sans être relu
        if ($video->hasBuyers()) {
            report(new RuntimeException("Vidéo {$video->id} modifiée après le contrôle, déjà achetée : retirée de la vente ({$reason})"));
            $video->update(['files_checked_at' => null, 'publish_requested_at' => null]);
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
            'files_checked_at' => null,
            'publish_requested_at' => null,
        ]);
    }

    /**
     * La mise en vente demandée pendant la relecture. Refusée entre-temps
     * (fiche vidée, vendeur qui ne vend plus, compte fermé) : la demande
     * tombe, la vidéo reste en brouillon et l'écran du vendeur le montre.
     */
    private function publishIfRequested(Video $video, VideoPublisher $publisher): void
    {
        if ($video->publish_requested_at === null || $video->status === VideoStatus::Published) {
            return;
        }
        try {
            $publisher->publish($video, (string) $video->seller_name);
        } catch (ValidationException $e) {
            $video->update(['publish_requested_at' => null]);
            report(new RuntimeException("Vidéo {$video->id} relue mais pas mise en vente : ".implode(' ', array_merge(...array_values($e->errors())))));
        }
    }
}
