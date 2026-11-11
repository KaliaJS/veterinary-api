<?php

namespace App\Services\Site;

use App\Enums\VideoCategory;
use App\Http\Resources\V1\VideoResource;
use App\Models\Organisation;
use App\Models\SiteWeb;
use App\Models\Video;
use App\Services\Videos\VideoAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Les vidéos de la clinique sur son site web (11.11.2026, décision de
 * Jim) : seulement celles que vend l'organisation du site, telles qu'un
 * visiteur sans compte les voit (destinées aux propriétaires, publiées) ;
 * jamais celles qu'un vétérinaire vend en son nom. Les rangées de la page
 * « Vidéos », à la Netflix : les nouveautés, puis une par catégorie.
 * Acheter et regarder se font sur Veterinary : le site ne fait que
 * présenter (aperçu d'une minute compris).
 */
class SiteVideos
{
    /** Une clinique en a peu : tout tient dans les rangées */
    public const int ROW_SIZE = 60;

    public function __construct(private VideoAccess $access) {}

    /** La page « Vidéos » paraît : l'éditeur ne l'a pas masquée et la clinique a une vidéo à montrer */
    public function isOpen(SiteWeb $site): bool
    {
        return $site->afficher_videos && $this->query($site->organisation)->exists();
    }

    /**
     * Les rangées de la page, au format de l'accueil des vidéos.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(Organisation $organisation, Request $request): array
    {
        $rows = [['key' => 'new', 'title' => 'Nouveautés', 'videos' => $this->present($this->latest($this->query($organisation))->get(), $request)]];
        $counts = $this->query($organisation)->whereNotNull('category')
            ->selectRaw('category, count(*) as total')->groupBy('category')->toBase()->pluck('total', 'category');
        foreach (VideoCategory::cases() as $category) {
            $total = (int) ($counts[$category->value] ?? 0);
            if ($total === 0) {
                continue;
            }
            $videos = $this->latest($this->query($organisation)->where('category', $category->value))->get();
            $rows[] = ['key' => 'category', 'category' => $category->value, 'title' => $category->label(), 'total' => $total, 'videos' => $this->present($videos, $request)];
        }

        // Une rangée vide ne s'affiche pas
        return array_values(array_filter($rows, fn (array $row) => $row['videos'] !== []));
    }

    /** @return Builder<Video> */
    private function query(Organisation $organisation): Builder
    {
        return Video::visibleTo(Video::query(), null)
            ->whereNull('seller_user_id')->where('organisation_id', $organisation->id);
    }

    /**
     * @param  Builder<Video>  $query
     * @return Builder<Video>
     */
    private function latest(Builder $query): Builder
    {
        return $query->withSeriesTotals()->with(['sellerUser', 'organisation'])
            ->latest('published_at')->orderByDesc('id')->limit(self::ROW_SIZE);
    }

    /**
     * Ce qu'un visiteur en a : la première minute, ou gratuite.
     *
     * @param  Collection<int, Video>  $videos
     * @return list<array<string, mixed>>
     */
    private function present(Collection $videos, Request $request): array
    {
        return $videos
            ->map(fn (Video $video) => [$video, $this->access->for($video, null, false)])
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->map(fn (array $pair) => VideoResource::seenBy($pair[0], $pair[1])->resolve($request))
            ->values()->all();
    }
}
