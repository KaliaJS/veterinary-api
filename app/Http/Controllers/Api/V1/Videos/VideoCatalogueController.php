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
use App\Services\Videos\VideoSellerProfile;
use App\Services\Videos\ViewingCertificates;
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
 * Sans compte (`/public/videos`, l'écran ouvert depuis l'accueil du site,
 * demande de Jim) : les vidéos des clients, avec leur aperçu ; regarder,
 * acheter et le reste demandent un compte.
 */
class VideoCatalogueController extends Controller
{
    public function __construct(
        private VideoAccess $access,
        private ContinueWatching $continueWatching,
        private VideoSellerProfile $sellerProfile,
        private ViewingCertificates $certificates,
    ) {}

    /** Rangées de l'accueil : autant de vidéos au plus par rangée. */
    public const ROW_SIZE = 20;

    /**
     * Le catalogue, par pages, les plus récentes d'abord ; une catégorie
     * (`category`), les gratuites (`free`, la catégorie « Gratuites » de
     * l'écran, demande de Jim) ou une recherche (`q` : titre, description,
     * vendeur, nom de catégorie) le filtrent.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['sometimes', 'nullable', Rule::enum(VideoCategory::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'free' => ['sometimes', 'boolean'],
        ]);
        $user = $this->viewer($request);
        $category = VideoCategory::tryFrom((string) $request->query('category'));
        $search = trim(str_replace(['%', '_'], ' ', (string) $request->query('q')));
        $videos = Video::visibleTo(Video::query(), $user)->withSeriesTotals()
            ->when($category !== null, fn (Builder $query) => $query->where('category', $category->value))
            ->when($request->boolean('free'), fn (Builder $query) => $query->where('price_cents', 0))
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
        $user = $this->viewer($request);
        $isNative = NativeApp::isIos($request);
        $rows = [];

        // « Continuer à voir » : ce qu'on a lancé et pas fini (ContinueWatching), une série à l'épisode à reprendre ;
        // rien pour un visiteur sans compte
        $organisations = $user === null ? [] : Video::organisationsOf($user);
        $continue = $user === null ? [] : array_map(function (array $entry) use ($request) {
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
        $freeTotal = 0;
        if (! $isNative) {
            $latest = Video::visibleTo(Video::query(), $user)->withSeriesTotals()->with($this->relations($user))->latest('published_at')->orderByDesc('id')->limit(self::ROW_SIZE)->get();
            $rows[] = ['key' => 'new', 'title' => 'Nouveautés', 'videos' => $this->present($latest, $user, $request, $organisations)];

            // Les vidéos gratuites (demande de Jim) : pour qui ne paie pas, juste après les nouveautés
            $free = Video::visibleTo(Video::query(), $user)->where('price_cents', 0)->withSeriesTotals()->with($this->relations($user))
                ->latest('published_at')->orderByDesc('id')->limit(self::ROW_SIZE)->get();
            $rows[] = ['key' => 'free', 'title' => 'Gratuites', 'videos' => $this->present($free, $user, $request, $organisations)];
            // Combien en tout : la catégorie « Gratuites » de l'écran le montre
            $freeTotal = Video::visibleTo(Video::query(), $user)->where('price_cents', 0)->count();

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
            'free_total' => $freeTotal,
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
        $user = $this->viewer($request);
        $model = Video::query()->where('uuid', $video)->firstOrFail();
        $access = $this->access->for($model, $user, $user !== null && $model->isOnSellerSide($user));
        $view = $user === null ? null : VideoView::query()->where('video_id', $model->id)->where('user_id', $user->id)->first();
        $certificate = $user === null ? null : ViewingCertificate::query()->where('video_id', $model->id)->where('user_id', $user->id)->first();
        // Une série : ses épisodes ajoutés (tous pour son vendeur), où en est la personne, et le certificat de la série
        if ($model->isSeries() && $access !== null) {
            // Le vendeur voit tout ; l'acheteur, chaque épisode ajouté un jour (même sorti de la vente) ; les autres, ceux en vente
            $episodes = $model->episodes()->get()->filter(fn (Video $episode) => match ($access) {
                'seller' => true,
                'owned' => VideoAccess::wasAdded($episode),
                default => $episode->status === VideoStatus::Published,
            })->values();
            // Achetée : la version à télécharger d'un épisode qui manque encore (assemblage raté pendant une panne) se relance
            if ($access === 'owned') {
                $episodes->filter(fn (Video $episode) => $episode->download_path === null && $episode->files_status === VideoFilesStatus::Ready)
                    ->each(fn (Video $episode) => AssembleVideoDownload::dispatch($episode->id, $episode->uploadStamp()));
            }
            $views = $user === null ? [] : VideoView::query()->where('user_id', $user->id)->whereIn('video_id', $episodes->pluck('id'))->get()->keyBy('video_id')->all();

            return VideoResource::seenBy($model, $access, $access === 'seller')->withEpisodes($episodes, $views, $certificate)
                ->withSellerProfile($this->sellerProfile->for($model));
        }
        if ($access !== null) {
            // Achetée, sa version à télécharger manque encore (assemblage raté pendant une panne) : on la relance
            if ($access === 'owned' && $model->download_path === null && $model->files_status === VideoFilesStatus::Ready) {
                AssembleVideoDownload::dispatch($model->id, $model->uploadStamp());
            }

            // Qui la présente (photo ou logo, présentation) : seulement dans sa fenêtre, pas dans les listes
            $resource = VideoResource::seenBy($model, $access, $access === 'seller')->withSellerProfile($this->sellerProfile->for($model));

            // Un visiteur sans compte n'a rien regardé : pas d'où il en est
            return $user === null ? $resource : $resource->withViewing($view, $certificate);
        }
        // Plus regardable (retirée, sortie de Plus) : qui l'a vue en entier y retrouve son certificat,
        // sans rien voir de ce que la vidéo est devenue depuis (titre figé du certificat, pas de prix ni de public).
        // Repartie en brouillon (refusée, en cours de renvoi) : seul un certificat déjà délivré la rouvre.
        // Une série : vue en entier, c'est chacun de ses épisodes ajoutés vu en entier
        $isCompleted = $model->isSeries()
            ? $user !== null && $this->certificates->hasCompletedSeries($model, $user)
            : $view?->completed_at !== null;
        $hasWatched = $isCompleted && $model->status !== VideoStatus::Draft;
        abort_if($model->published_at === null || (! $hasWatched && $certificate === null), 404);

        return response()->json(['data' => [
            'id' => $model->uuid,
            'kind' => $model->kind->value,
            'title' => $certificate->video_title ?? $model->title,
            'seller' => ['name' => $certificate->seller_name ?? $model->seller_name],
            'access' => null,
            'viewing' => [
                'watched_seconds' => $view->watched_seconds ?? 0,
                'counted' => $view?->counted_at !== null,
                'completed' => $isCompleted,
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

    /**
     * Qui regarde : la personne connectée, ou personne sur les adresses
     * publiques (`/public/videos`), même si un jeton accompagne la requête :
     * le catalogue sans compte ne montre jamais ce qui est à quelqu'un.
     */
    private function viewer(Request $request): ?User
    {
        return $request->is('api/v1/public/*') ? null : $request->user();
    }

    /** Le vendeur et les achats payés de la personne, chargés pour toute la liste. */
    private function relations(?User $user): array
    {
        if ($user === null) {
            return ['sellerUser', 'organisation'];
        }

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
    private function present(Collection $videos, ?User $user, Request $request, ?array $organisations = null): array
    {
        $organisations ??= $user === null ? [] : Video::organisationsOf($user);

        return $videos
            ->map(fn (Video $video) => [$video, $this->access->for($video, $user, $user !== null && $video->isOnSellerSide($user, $organisations))])
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
