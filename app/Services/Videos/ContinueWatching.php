<?php

namespace App\Services\Videos;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoView;
use Illuminate\Support\Collection;

/**
 * « Continuer à voir » (31.10.2026, demande de Jim) : ce qu'on a lancé et
 * pas fini, le plus récent d'abord, comme sur Netflix.
 *
 * - Une vidéo commencée (10 secondes au moins : un lancement par erreur ne
 *   compte pas), pas vue en entier, qu'on peut encore regarder ; l'aperçu
 *   gratuit compte tant que sa minute n'est pas finie.
 * - Une série paraît une seule fois, à l'épisode à reprendre : celui
 *   commencé, ou, le dernier vu en entier, le suivant pas encore vu.
 * - Une série vue jusqu'au bout, une vidéo finie, ou rien depuis deux mois :
 *   elles quittent la rangée.
 */
class ContinueWatching
{
    /** Secondes en dessous desquelles un lancement ne compte pas. */
    public const MIN_SECONDS = 10;

    /** Ce qui n'a pas bougé depuis plus longtemps quitte la rangée. */
    public const STALE_DAYS = 60;

    public function __construct(private VideoAccess $access) {}

    /**
     * @param  list<int>  $organisations  celles dont la personne gère les vidéos
     * @return list<array{item: Video, access: string, view: ?VideoView, episode: ?Video}>
     */
    public function for(User $user, array $organisations, int $limit): array
    {
        $views = VideoView::query()->where('user_id', $user->id)->where('watched_seconds', '>', 0)
            ->where('last_progress_at', '>=', now()->subDays(self::STALE_DAYS))
            ->orderByDesc('last_progress_at')->orderByDesc('id')->limit(200)
            ->with(['video.series'])->get();
        $completedByVideo = $views->filter(fn (VideoView $view) => $view->completed_at !== null)->pluck('video_id')->flip();

        $items = [];
        $seenSeries = [];
        foreach ($views as $view) {
            $video = $view->video;
            if ($video === null || $video->status === VideoStatus::Draft) {
                continue;
            }
            $access = $this->access->for($video, $user, $video->isOnSellerSide($user, $organisations));
            if ($access === null) {
                continue;
            }
            if ($video->isEpisode()) {
                $series = $video->series;
                // Un épisode lancé par erreur, ou dont la minute gratuite est finie, ne décide pas de la série :
                // un autre épisode, regardé avant, peut encore la faire paraître
                $isMisclick = $view->completed_at === null && $view->watched_seconds < self::MIN_SECONDS;
                $isPreviewOver = $view->completed_at === null && $access === 'preview' && $view->watched_seconds >= Video::PREVIEW_SECONDS - 5;
                if ($series === null || isset($seenSeries[$series->id]) || $isMisclick || $isPreviewOver) {
                    continue;
                }
                $seenSeries[$series->id] = true;
                $episode = $view->completed_at === null ? $video : $this->nextEpisode($series, $video, $user, $completedByVideo);
                if ($episode === null) {
                    continue;
                }
                $seriesAccess = $this->access->for($series, $user, $series->isOnSellerSide($user, $organisations));
                if ($seriesAccess === null) {
                    continue;
                }
                $items[] = ['item' => $series, 'access' => $seriesAccess, 'view' => $episode->is($video) ? $view : null, 'episode' => $episode];
            } else {
                if ($view->completed_at !== null || $view->watched_seconds < self::MIN_SECONDS) {
                    continue;
                }
                // L'aperçu gratuit : seulement tant que sa minute n'est pas finie
                if ($access === 'preview' && $view->watched_seconds >= Video::PREVIEW_SECONDS - 5) {
                    continue;
                }
                $items[] = ['item' => $video, 'access' => $access, 'view' => $view, 'episode' => null];
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * L'épisode qui suit un épisode vu en entier, pas encore vu lui-même ;
     * null si la série est finie.
     *
     * @param  Collection<int, int>  $completedByVideo
     */
    private function nextEpisode(Video $series, Video $after, User $user, Collection $completedByVideo): ?Video
    {
        return $series->episodes()->where('status', VideoStatus::Published->value)
            ->where('episode_number', '>', (int) $after->episode_number)
            ->get()
            ->first(fn (Video $episode) => ! $completedByVideo->has($episode->id)
                && ! VideoView::query()->where('video_id', $episode->id)->where('user_id', $user->id)->whereNotNull('completed_at')->exists());
    }
}
