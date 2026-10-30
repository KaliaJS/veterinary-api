<?php

namespace App\Services\Videos;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoView;
use Illuminate\Support\Facades\DB;

/**
 * Ce qui a été regardé (31.10.2026). Le lecteur envoie les intervalles
 * vus ; le serveur les fusionne et crédite au plus le temps écoulé depuis
 * la dernière fois (deux fois, pour la lecture accélérée), si bien qu'on
 * ne gagne pas une minute en une seconde. Une vue compte une fois, à plus
 * d'une minute ; vue en entier à 95 %.
 */
class VideoViews
{
    /** Marge du premier envoi, et lecture accélérée jusqu'à deux fois. */
    private const GRACE_SECONDS = 15;

    private const MAX_SPEED = 2;

    /** Ouvre (ou reprend) le visionnage : l'horloge de crédit part d'ici. */
    public function start(Video $video, User $user): VideoView
    {
        return VideoView::query()->firstOrCreate(['video_id' => $video->id, 'user_id' => $user->id], ['last_progress_at' => now(), 'watched_ranges' => []]);
    }

    /**
     * @param  list<array{0: float|int, 1: float|int}>  $ranges  secondes regardées
     * @param  string  $access  'seller', 'owned', 'plus' ou 'preview'
     */
    public function record(Video $video, User $user, array $ranges, string $access): VideoView
    {
        $duration = (float) $video->duration_seconds;
        $limit = $access === 'preview' ? min($duration, Video::PREVIEW_SECONDS) : $duration;

        return DB::transaction(function () use ($video, $user, $ranges, $access, $duration, $limit): VideoView {
            $view = VideoView::query()->where('video_id', $video->id)->where('user_id', $user->id)->lockForUpdate()->first()
                ?? $this->start($video, $user);

            $merged = self::merge([...($view->watched_ranges ?? []), ...$ranges], $limit);
            $total = (int) floor(array_sum(array_map(fn ($r) => $r[1] - $r[0], $merged)));
            $since = $view->last_progress_at ? max(0, now()->getTimestamp() - $view->last_progress_at->getTimestamp()) : 0;
            // La marge ne vaut qu'au premier envoi : sinon, des envois rapprochés l'additionneraient
            $grace = $view->watched_seconds === 0 ? self::GRACE_SECONDS : 0;
            $credited = min($total, $view->watched_seconds + $since * self::MAX_SPEED + $grace);

            $view->watched_ranges = $merged;
            $view->watched_seconds = max($view->watched_seconds, $credited);
            $view->last_progress_at = now();

            // Plus d'une minute : la vue compte, une seule fois
            if ($view->counted_at === null && $view->watched_seconds > VideoView::COUNT_AFTER_SECONDS && $access !== 'preview') {
                $view->counted_at = now();
                $view->counted_via_plus = $access === 'plus';
            }
            // Vue en entier : 95 % de la durée
            if ($view->completed_at === null && $access !== 'preview' && $duration > 0 && $view->watched_seconds >= $duration * VideoView::COMPLETION_RATIO) {
                $view->completed_at = now();
            }
            $view->save();

            return $view;
        });
    }

    /**
     * Fusionne des intervalles, bornés à [0, limite].
     *
     * @param  array<int, mixed>  $ranges
     * @return list<array{0: float, 1: float}>
     */
    public static function merge(array $ranges, float $limit): array
    {
        $clean = [];
        foreach ($ranges as $range) {
            if (! is_array($range) || count($range) !== 2 || ! is_numeric($range[0] ?? null) || ! is_numeric($range[1] ?? null)) {
                continue;
            }
            $start = max(0.0, min((float) $range[0], $limit));
            $end = max(0.0, min((float) $range[1], $limit));
            if ($end > $start) {
                $clean[] = [$start, $end];
            }
        }
        usort($clean, fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($clean as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return array_map(fn ($r) => [round($r[0], 3), round($r[1], 3)], $merged);
    }
}
