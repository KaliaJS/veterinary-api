<?php

namespace App\Http\Resources\V1;

use App\Models\Video;
use App\Models\VideoView;
use App\Models\ViewingCertificate;
use App\Services\Videos\VideoPosters;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une vidéo de formation (31.10.2026). Son vendeur voit aussi l'état de
 * ses fichiers et sa taille ; un acheteur voit ce qu'il peut faire
 * (regarder en entier, télécharger).
 *
 * @mixin Video
 */
class VideoResource extends JsonResource
{
    private ?string $access = null;

    private bool $isSeller = false;

    /** Les épisodes d'une série ouverte, et où en est la personne. @var list<array<string, mixed>>|null */
    private ?array $episodes = null;

    /** L'épisode commencé d'une série (« Continuer à regarder »). @var array<string, mixed>|null */
    private ?array $resume = null;

    /** Vue par quelqu'un : ce qu'il peut en faire, et s'il en est le vendeur. */
    /** @var array{watched_seconds: int, counted: bool, completed: bool, certificate_code: ?string}|null */
    private ?array $viewing = null;

    /** Où en est la personne (vidéo ouverte seule) : secondes vues, vue comptée, vue en entier, certificat. */
    public function withViewing(?VideoView $view, ?ViewingCertificate $certificate): self
    {
        $this->viewing = [
            'watched_seconds' => $view->watched_seconds ?? 0,
            'counted' => $view?->counted_at !== null,
            'completed' => $view?->completed_at !== null,
            // Ce qu'il faut avoir regardé pour le certificat : l'écran ne refait pas le calcul
            'required_seconds' => $this->duration_seconds !== null ? VideoView::requiredSeconds((float) $this->duration_seconds) : null,
            'certificate_code' => $certificate?->code,
        ];

        return $this;
    }

    /**
     * Une série ouverte : ses épisodes (ajoutés ; tous pour son vendeur), où
     * en est la personne dans chacun, et le certificat de la série, qui se
     * délivre une fois tous vus en entier.
     *
     * @param  iterable<Video>  $episodes
     * @param  array<int, VideoView>  $views  par épisode
     */
    public function withEpisodes(iterable $episodes, array $views, ?ViewingCertificate $certificate): self
    {
        $list = [];
        $completed = 0;
        foreach ($episodes as $episode) {
            $view = $views[$episode->id] ?? null;
            $completed += $view?->completed_at !== null ? 1 : 0;
            $list[] = [
                'id' => $episode->uuid,
                'number' => $episode->episode_number,
                'title' => $episode->title,
                'description' => $episode->description,
                'duration_seconds' => $episode->duration_seconds === null ? null : (float) $episode->duration_seconds,
                'poster' => VideoPosters::for($episode),
                'status' => $episode->status->value,
                'files_status' => $this->isSeller ? $episode->files_status->value : null,
                'viewing' => ['watched_seconds' => $view->watched_seconds ?? 0, 'completed' => $view?->completed_at !== null],
            ];
        }
        $this->episodes = $list;
        $added = array_filter($list, fn (array $episode) => $episode['status'] === 'published');
        $this->viewing = [
            'watched_seconds' => array_sum(array_map(fn (array $episode) => $episode['viewing']['watched_seconds'], $added)),
            'counted' => false,
            'completed' => $added !== [] && $completed >= count($added),
            'completed_episodes' => $completed,
            'required_seconds' => null,
            'certificate_code' => $certificate?->code,
        ];

        return $this;
    }

    /** L'épisode à reprendre d'une série (commencé, ou le suivant d'un épisode fini), pour « Continuer à voir ». */
    public function withResume(Video $episode, ?VideoView $view): self
    {
        $this->resume = [
            'episode_id' => $episode->uuid,
            'episode_number' => $episode->episode_number,
            'title' => $episode->title,
            'watched_seconds' => $view->watched_seconds ?? 0,
            'duration_seconds' => $episode->duration_seconds === null ? null : (float) $episode->duration_seconds,
        ];

        return $this;
    }

    public static function seenBy(Video $video, ?string $access, bool $isSeller = false): self
    {
        $resource = new self($video);
        $resource->access = $access;
        $resource->isSeller = $isSeller;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isSeller = $this->isSeller;
        $access = $this->access;

        $data = [
            'id' => $this->uuid,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'description' => $this->description,
            'price_cents' => $this->price_cents,
            'audience' => $this->audience?->value,
            'category' => $this->category?->value,
            'category_label' => $this->category?->label(),
            'poster' => VideoPosters::for($this->resource),
            'in_plus' => $this->in_plus,
            'status' => $this->status->value,
            'duration_seconds' => $this->duration_seconds === null ? null : (float) $this->duration_seconds,
            'width' => $this->width,
            'height' => $this->height,
            'seller' => ['name' => $this->seller_name, 'kind' => $this->seller_clinique_id !== null ? 'clinic' : 'vet'],
            'published_at' => $this->published_at?->toIso8601String(),
            'access' => $access,
            'can_download' => $access === 'owned' && $this->download_path !== null,
        ];
        // Une série : ses épisodes ajoutés et leur durée totale ; un épisode : sa série et son numéro
        if ($this->resource->isSeries()) {
            $data['episodes_count'] = (int) ($this->published_episodes_count ?? $this->resource->episodes()->where('status', 'published')->count());
            $data['duration_seconds'] = (float) ($this->published_episodes_seconds ?? $this->resource->episodes()->where('status', 'published')->sum('duration_seconds'));
            $data['can_download'] = false;
        }
        if ($this->resource->isEpisode()) {
            $data['series'] = ['id' => $this->series?->uuid, 'title' => $this->series?->title];
            $data['episode_number'] = $this->episode_number;
        }
        if ($this->episodes !== null) {
            $data['episodes'] = $this->episodes;
        }
        if ($this->resume !== null) {
            $data['resume'] = $this->resume;
        }
        if ($this->viewing !== null) {
            $data['viewing'] = $this->viewing;
        }
        if ($isSeller) {
            $data['files_status'] = $this->files_status->value;
            $data['publish_requested'] = $this->publish_requested_at !== null;
            $data['rejection_reason'] = $this->rejection_reason;
            $data['size_bytes'] = (int) $this->size_bytes;
            $data['sales_count'] = $this->sales_count ?? $this->purchases()->whereNotNull('paid_at')->count();
        }

        return $data;
    }
}
