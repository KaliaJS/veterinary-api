<?php

namespace App\Http\Controllers\Api\V1\Videos;

use App\Enums\VideoCategory;
use App\Enums\VideoFilesStatus;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VideoResource;
use App\Jobs\AssembleVideoDownload;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoView;
use App\Models\ViewingCertificate;
use App\Services\Videos\ContinueWatching;
use App\Services\Videos\VideoAccess;
use App\Support\NativeApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Le catalogue des vidéos de formation (31.10.2026), pour tout compte :
 * les vidéos destinées aux clients, et pour un vétérinaire vérifié celles
 * destinées aux vétérinaires. Ce qu'il a acheté reste à lui, même retiré.
 */
class VideoCatalogueController extends Controller
{
    public function __construct(private VideoAccess $access, private ContinueWatching $continueWatching) {}

    /** Rangées de l'accueil : autant de vidéos au plus par rangée. */
    public const ROW_SIZE = 20;

    /**
     * Le catalogue, par pages, les plus récentes d'abord ; une catégorie
     * (`category`) ou une recherche (`q` : titre, description, vendeur, nom
     * de catégorie) le filtrent.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['sometimes', 'nullable', Rule::enum(VideoCategory::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $category = VideoCategory::tryFrom((string) $request->query('category'));
        $search = trim(str_replace(['%', '_'], ' ', (string) $request->query('q')));
        $videos = Video::visibleTo(Video::query(), $user)->withSeriesTotals()
            ->when($category !== null, fn (Builder $query) => $query->where('category', $category->value))
            ->when($search !== '', fn (Builder $query) => $this->search($query, $search))
            ->with($this->relations($user))
            ->latest('published_at')->orderByDesc('id')->paginate(24);

        return response()->json([
            'data' => $this->present($videos->getCollection(), $user, $request),
            'meta' => ['current_page' => $videos->currentPage(), 'last_page' => $videos->lastPage(), 'total' => $videos->total()],
        ]);
    }

    /**
     * L'accueil de l'écran « Vidéos » (31.10.2026) : continuer à voir ce
     * qu'on a lancé, les nouveautés, puis une rangée par catégorie, dans
     * l'ordre de la liste (« Mes vidéos » a son onglet, demande de Jim).
     * Dans l'app iPhone, rien ne se vend : « Continuer à voir » seulement.
     */
    public function home(Request $request): JsonResponse
    {
        $user = $request->user();
        $isNative = NativeApp::isIos($request);
        $rows = [];

        // « Continuer à voir » : ce qu'on a lancé et pas fini (ContinueWatching), une série à l'épisode à reprendre
        $organisations = Video::organisationsOf($user);
        $continue = array_map(function (array $entry) use ($request) {
            $resource = VideoResource::seenBy($entry['item'], $entry['access']);
            if ($entry['episode'] !== null) {
                $resource->withResume($entry['episode'], $entry['view']);
            } elseif ($entry['view'] !== null) {
                $resource->withViewing($entry['view'], null);
            }

            return $resource->resolve($request);
        }, $this->continueWatching->for($user, $organisations, self::ROW_SIZE));
        $rows[] = ['key' => 'continue', 'title' => 'Continuer à voir', 'videos' => $continue];

        $categories = [];
        if (! $isNative) {
            $latest = Video::visibleTo(Video::query(), $user)->withSeriesTotals()->with($this->relations($user))->latest('published_at')->orderByDesc('id')->limit(self::ROW_SIZE)->get();
            $rows[] = ['key' => 'new', 'title' => 'Nouveautés', 'videos' => $this->present($latest, $user, $request, $organisations)];

            // Les vidéos gratuites (demande de Jim) : pour qui ne paie pas, juste après les nouveautés
            $free = Video::visibleTo(Video::query(), $user)->where('price_cents', 0)->withSeriesTotals()->with($this->relations($user))
                ->latest('published_at')->orderByDesc('id')->limit(self::ROW_SIZE)->get();
            $rows[] = ['key' => 'free', 'title' => 'Gratuites', 'videos' => $this->present($free, $user, $request, $organisations)];

            $counts = Video::visibleTo(Video::query(), $user)->whereNotNull('category')
                ->selectRaw('category, count(*) as total')->groupBy('category')->toBase()->pluck('total', 'category');
            foreach (VideoCategory::cases() as $category) {
                $total = (int) ($counts[$category->value] ?? 0);
                if ($total === 0) {
                    continue;
                }
                $categories[] = ['key' => $category->value, 'label' => $category->label(), 'total' => $total];
                $videos = Video::visibleTo(Video::query(), $user)->where('category', $category->value)
                    ->withSeriesTotals()->with($this->relations($user))->latest('published_at')->orderByDesc('id')->limit(self::ROW_SIZE)->get();
                $rows[] = ['key' => 'category', 'category' => $category->value, 'title' => $category->label(), 'total' => $total, 'videos' => $this->present($videos, $user, $request, $organisations)];
            }
        }

        return response()->json(['data' => [
            // Une rangée vide ne s'affiche pas
            'rows' => array_values(array_filter($rows, fn (array $row) => $row['videos'] !== [])),
            'categories' => $categories,
        ]]);
    }

    /**
     * « Mes vidéos » (septième vérification) : ses achats, à vie, même
     * retirés de la vente, et, pour un abonné Plus, toutes les vidéos
     * incluses qu'il peut regarder ; par pages, les plus récentes d'abord.
     * Dans l'app iPhone, sans catalogue, c'est le seul chemin vers elles.
     */
    public function library(Request $request): JsonResponse
    {
        $user = $request->user();
        $videos = $this->libraryQuery($user)->withSeriesTotals()
            ->with($this->relations($user))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(24);

        return response()->json([
            'data' => $this->present($videos->getCollection(), $user, $request),
            'meta' => ['current_page' => $videos->currentPage(), 'last_page' => $videos->lastPage(), 'total' => $videos->total()],
        ]);
    }

    public function show(Request $request, string $video): VideoResource|JsonResponse
    {
        $user = $request->user();
        $model = Video::query()->where('uuid', $video)->firstOrFail();
        $access = $this->access->for($model, $user, $model->isOnSellerSide($user));
        $view = VideoView::query()->where('video_id', $model->id)->where('user_id', $user->id)->first();
        $certificate = ViewingCertificate::query()->where('video_id', $model->id)->where('user_id', $user->id)->first();
        // Une série : ses épisodes ajoutés (tous pour son vendeur), où en est la personne, et le certificat de la série
        if ($model->isSeries() && $access !== null) {
            $episodes = $model->episodes()->when($access !== 'seller', fn ($episode) => $episode->where('status', VideoStatus::Published->value))->get();
            $views = VideoView::query()->where('user_id', $user->id)->whereIn('video_id', $episodes->pluck('id'))->get()->keyBy('video_id')->all();

            return VideoResource::seenBy($model, $access, $access === 'seller')->withEpisodes($episodes, $views, $certificate);
        }
        if ($access !== null) {
            // Achetée, sa version à télécharger manque encore (assemblage raté pendant une panne) : on la relance
            if ($access === 'owned' && $model->download_path === null && $model->files_status === VideoFilesStatus::Ready) {
                AssembleVideoDownload::dispatch($model->id, $model->uploadStamp());
            }

            return VideoResource::seenBy($model, $access, $access === 'seller')->withViewing($view, $certificate);
        }
        // Plus regardable (retirée, sortie de Plus) : qui l'a vue en entier y retrouve son certificat,
        // sans rien voir de ce que la vidéo est devenue depuis (titre figé du certificat, pas de prix ni de public).
        // Repartie en brouillon (refusée, en cours de renvoi) : seul un certificat déjà délivré la rouvre
        $hasWatched = $view?->completed_at !== null && $model->status !== VideoStatus::Draft;
        abort_if($model->published_at === null || (! $hasWatched && $certificate === null), 404);

        return response()->json(['data' => [
            'id' => $model->uuid,
            'title' => $certificate->video_title ?? $model->title,
            'seller' => ['name' => $certificate->seller_name ?? $model->seller_name],
            'access' => null,
            'viewing' => [
                'watched_seconds' => $view->watched_seconds ?? 0,
                'counted' => $view?->counted_at !== null,
                'completed' => $view?->completed_at !== null,
                'certificate_code' => $certificate?->code,
            ],
        ]]);
    }

    /**
     * Ses vidéos : ses achats, à vie, même retirés de la vente, et, pour un
     * abonné Plus, toutes les vidéos incluses qu'il peut regarder.
     *
     * @return Builder<Video>
     */
    private function libraryQuery(User $user): Builder
    {
        return Video::query()->where(function (Builder $mine) use ($user): void {
            $mine->whereHas('purchases', fn (Builder $purchase) => $purchase->where('user_id', $user->id)->whereNotNull('paid_at'));
            if ($user->aPlus()) {
                $mine->orWhere(fn (Builder $plus) => Video::visibleTo($plus, $user)->where('in_plus', true));
            }
        });
    }

    /** Le vendeur et les achats payés de la personne, chargés pour toute la liste. */
    private function relations(User $user): array
    {
        return ['sellerUser', 'organisation', 'purchases' => fn ($purchase) => $purchase->where('user_id', $user->id)->whereNotNull('paid_at')];
    }

    /**
     * Les vidéos vues par la personne ; rien sans accès : jamais une vidéo
     * montrée comme achetée par erreur.
     *
     * @param  Collection<int, Video>  $videos
     * @param  list<int>|null  $organisations  les organisations dont la personne gère les vidéos, lues une fois par requête
     * @return list<array<string, mixed>>
     */
    private function present(Collection $videos, User $user, Request $request, ?array $organisations = null): array
    {
        $organisations ??= Video::organisationsOf($user);

        return $videos
            ->map(fn (Video $video) => [$video, $this->access->for($video, $user, $video->isOnSellerSide($user, $organisations))])
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->map(fn (array $pair) => VideoResource::seenBy($pair[0], $pair[1])->resolve($request))
            ->values()->all();
    }

    /**
     * Une recherche : au début d'un mot du titre, de la description ou du
     * nom du vendeur, et dans les catégories, sans tenir compte des accents
     * ni des majuscules pour celles-ci. Une
     * catégorie se trouve par son nom entier (« NAC », « premiers secours »)
     * ou par le début de son nom ou d'un de ses mots, dès quatre lettres
     * (« chat » trouve Chats, « secours » Premiers secours) : pendant qu'on
     * tape « chiot », « chi » ne remonte ni Chiens ni Chirurgie.
     *
     * @param  Builder<Video>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $needle = Str::squish(Str::lower(Str::ascii($search)));
        $matches = function (VideoCategory $category) use ($needle): bool {
            $label = Str::lower(Str::ascii($category->label()));
            if ($label === $needle) {
                return true;
            }

            return mb_strlen($needle) >= 4 && (str_starts_with($label, $needle)
                || collect(explode(' ', $label))->contains(fn (string $word) => str_starts_with($word, $needle)));
        };
        $categories = array_values(array_map(fn (VideoCategory $category) => $category->value, array_filter(VideoCategory::cases(), $matches)));
        // Au début d'un mot seulement : « chat » ne trouve pas « achat », « nac » pas « menace »
        $wordStarts = fn (Builder $match, string $column) => $match->orWhere(fn (Builder $inColumn) => $inColumn
            ->where($column, 'like', $search.'%')
            ->orWhere($column, 'like', '% '.$search.'%')
            ->orWhere($column, 'like', '%’'.$search.'%')
            ->orWhere($column, 'like', "%'".$search.'%')
            ->orWhere($column, 'like', '%-'.$search.'%')
            ->orWhere($column, 'like', '%('.$search.'%')
            ->orWhere($column, 'like', '%«'.$search.'%')
            ->orWhere($column, 'like', "%\u{00A0}".$search.'%')
            ->orWhere($column, 'like', "%\u{202F}".$search.'%')
            ->orWhere($column, 'like', '%/'.$search.'%')
            ->orWhere($column, 'like', '%.'.$search.'%')
            ->orWhere($column, 'like', '%"'.$search.'%')
            ->orWhere($column, 'like', "%\n".$search.'%'));
        $query->where(function (Builder $match) use ($wordStarts, $categories): void {
            foreach (['title', 'description', 'seller_name'] as $column) {
                $wordStarts($match, $column);
            }
            if ($categories !== []) {
                $match->orWhereIn('category', $categories);
            }
            // Une série se trouve aussi par le titre d'un de ses épisodes ajoutés
            $match->orWhereHas('episodes', fn (Builder $episode) => $episode->where('status', VideoStatus::Published->value)
                ->where(fn (Builder $titled) => $wordStarts($titled, 'title')));
        });
    }
}
