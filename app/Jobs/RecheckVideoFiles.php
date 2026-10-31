<?php

namespace App\Jobs;

use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Events\VideoUpdated;
use App\Models\User;
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

    /** Le verrou couvre l'attente de la passe « final » (deux heures), tous les essais et leurs attentes, puis tombe de lui-même */
    public int $uniqueFor = 21600;

    /** Une panne du stockage : on réessaie dans 5, 15 puis 45 minutes */
    public array $backoff = [300, 900, 2700];

    /** Ce que la vidéo peut peser de plus qu'au contrôle sans qu'on y voie un ajout (arrondis, listes) */
    private const GROWTH_TOLERANCE = 1_000_000;

    /** Ce que la relecture a refusé sans que le vendeur soit là : dit à son écran */
    private ?string $notice = null;

    /** Secondes d'attente du verrou des fichiers (l'assemblage du téléchargement en cours) */
    public int $lockWait = 600;

    /** La version à télécharger manque : son assemblage part une fois le verrou des fichiers rendu */
    private bool $assembleAfter = false;

    /**
     * @param  string  $pass  « now » au contrôle et à la mise en vente, « late » après l'expiration des adresses
     * @param  int|null  $upload  l'envoi relu, reconnu à son heure de départ : un nouvel envoi a ses propres
     *                            relectures (sixième vérification) ; null : l'envoi du moment
     */
    public function __construct(public int $videoId, public string $pass = 'now', public ?int $upload = null) {}

    public function uniqueId(): string
    {
        return $this->videoId.'-'.$this->pass.'-'.($this->upload ?? 0);
    }

    /** La vidéo, si elle porte encore, prêts, les fichiers de l'envoi que cette relecture juge. */
    private function current(): ?Video
    {
        $video = Video::query()->find($this->videoId);

        return $video !== null && $video->files_status === VideoFilesStatus::Ready
            && ($this->upload === null || $video->uploadStamp() === $this->upload) ? $video : null;
    }

    /**
     * Relue nulle part après tous les essais : on ne vend pas ce qu'on n'a pas
     * pu relire. Rien ne s'efface ; sans acheteur, elle repart en brouillon,
     * achetée, elle quitte seulement la vente.
     */
    public function failed(?Throwable $exception): void
    {
        $video = $this->current();
        if ($video === null) {
            return;
        }
        // Une panne longue n'est pas un mauvais fichier : rien ne s'efface, la vidéo quitte la vente
        // le temps d'être relue (la mise en vente relance la relecture)
        report(new RuntimeException("Vidéo {$video->id} pas relue après tous les essais : hors de la vente en attendant", 0, $exception));
        $withdraw = $video->status === VideoStatus::Published && $video->hasBuyers();
        $wasRequested = $video->publish_requested_at !== null;
        $video->update([
            'files_checked_at' => null,
            'publish_requested_at' => null,
            'publish_requested_by' => null,
            'status' => $video->status === VideoStatus::Published ? ($withdraw ? VideoStatus::Withdrawn : VideoStatus::Draft) : $video->status,
            'withdrawn_at' => $withdraw ? now() : $video->withdrawn_at,
        ]);
        VideoUpdated::announce($video, $wasRequested ? $this->named($video).' n’a pas pu être relue, le stockage ne répond pas : elle n’est pas mise en vente. Réessayez plus tard.' : null);
    }

    public function handle(HlsPackage $package, VideoStorage $storage, VideoPublisher $publisher): void
    {
        $video = $this->current();
        if ($video === null) {
            return;
        }
        // Jamais en même temps que l'assemblage du téléchargement : chacun lit puis écrit la taille
        Video::filesLock($this->videoId)->block($this->lockWait, fn () => $this->check($video, $package, $storage, $publisher));
        if ($this->assembleAfter) {
            AssembleVideoDownload::dispatch($video->id, $video->uploadStamp());
        }
        // Relue, mise en vente, retirée ou refusée : l'écran du vendeur suit
        VideoUpdated::announce($video->refresh(), $this->notice);
    }

    private function check(Video $video, HlsPackage $package, VideoStorage $storage, VideoPublisher $publisher): void
    {
        $result = $package->inspect($video, [AssembleVideoDownload::FILE], everySegment: true);
        if ($result['ok'] === false && ! empty($result['transient'])) {
            // Rien n'est jugé sur une panne : la file réessaiera
            throw new RuntimeException('Stockage injoignable pendant la relecture de la vidéo '.$video->id);
        }
        // Un nouvel envoi a commencé pendant la lecture : ce paquet n'est plus le sien, ses propres relectures le jugeront
        $fresh = $this->current();
        if ($fresh === null) {
            return;
        }
        $video = $fresh;
        // Une version à télécharger posée au bucket sans être notée (assemblage interrompu) n'est ni un ajout
        // ni comptée : l'assemblage la refera et l'ajoutera lui-même
        $measured = $result['ok'] ? $result['size'] - ($video->download_path === null ? $result['kept_bytes'] : 0) : 0;
        // Plus lourde qu'au contrôle : des fichiers ont été remplacés ou ajoutés depuis
        $grown = $result['ok'] && $measured > (int) $video->size_bytes + self::GROWTH_TOLERANCE;
        if ($result['ok'] && ! $grown) {
            $video->update(['size_bytes' => $measured, 'renditions' => $result['renditions'], 'duration_seconds' => $result['duration'], 'files_checked_at' => now()]);
            // La version à télécharger manque encore (assemblage raté) : une chance de plus à chaque relecture
            $this->assembleAfter = $video->download_path === null;
            $this->publishIfRequested($video->refresh(), $publisher);

            return;
        }
        $reason = $result['ok'] ? 'Des fichiers ont été ajoutés après le contrôle : renvoyez la vidéo.' : $result['reason'];

        // Achetée entre-temps : jamais effacée, ceux qui l'ont payée la gardent ; elle quitte seulement la vente
        // La remettre en vente relance une relecture complète : rien ne se revend sans être relu
        if ($video->hasBuyers()) {
            report(new RuntimeException("Vidéo {$video->id} modifiée après le contrôle, déjà achetée : retirée de la vente ({$reason})"));
            $video->update(['files_checked_at' => null, 'publish_requested_at' => null, 'publish_requested_by' => null]);
            if ($video->status === VideoStatus::Published) {
                $video->update(['status' => VideoStatus::Withdrawn, 'withdrawn_at' => now()]);
            }

            return;
        }

        $storage->deleteAll($video);
        $video->update([
            'files_status' => VideoFilesStatus::Rejected,
            'rejection_reason' => $reason,
            // En vente ou retirée, sans acheteur : repart en brouillon, le vendeur peut la renvoyer
            'status' => in_array($video->status, [VideoStatus::Published, VideoStatus::Withdrawn], true) ? VideoStatus::Draft : $video->status,
            'size_bytes' => 0,
            'renditions' => null,
            'download_path' => null,
            'files_checked_at' => null,
            'publish_requested_at' => null,
            'publish_requested_by' => null,
        ]);
    }

    /**
     * La mise en vente demandée pendant la relecture. Refusée entre-temps
     * (fiche vidée, vendeur qui ne vend plus, compte fermé, personne qui a
     * perdu le droit de publier pour la clinique) : la demande tombe, la
     * vidéo reste en brouillon et l'écran du vendeur dit pourquoi.
     */
    private function publishIfRequested(Video $video, VideoPublisher $publisher): void
    {
        if ($video->publish_requested_at === null || $video->status === VideoStatus::Published) {
            return;
        }
        try {
            $this->assertRequesterMayPublish($video);
            $publisher->publish($video, (string) $video->seller_name);
        } catch (ValidationException $e) {
            $reasons = implode(' ', array_merge(...array_values($e->errors())));
            $video->update(['publish_requested_at' => null, 'publish_requested_by' => null]);
            // Le titre : sur le canal de la clinique, chacun de ceux qui gèrent ses vidéos le reçoit
            $this->notice = $this->named($video).' n’a pas pu être mise en vente. '.$reasons;
            report(new RuntimeException("Vidéo {$video->id} relue mais pas mise en vente : ".$reasons));
        }
    }

    /** La vidéo nommée dans un avis : son titre, ou « La vidéo » s'il a été vidé entre-temps. */
    private function named(Video $video): string
    {
        return blank($video->title) ? 'La vidéo' : '« '.$video->title.' »';
    }

    /** Une vidéo de clinique : qui l'a demandée doit encore pouvoir la publier (identité vérifiée, droit Organisation). */
    private function assertRequesterMayPublish(Video $video): void
    {
        if ($video->organisation_id === null) {
            return;
        }
        $requester = $video->publish_requested_by !== null ? User::query()->find($video->publish_requested_by) : null;
        if ($requester === null || ! $requester->estVerifie() || ! in_array((int) $video->organisation_id, Video::organisationsOf($requester), true)) {
            throw ValidationException::withMessages(['seller' => 'La personne qui l’a demandée ne peut plus publier pour la clinique : demandez à nouveau la mise en vente.']);
        }
    }
}
