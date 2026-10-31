<?php

namespace App\Http\Controllers\Api\V1\Videos;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VideoResource;
use App\Models\Video;
use App\Models\VideoView;
use App\Models\ViewingCertificate;
use App\Services\Videos\VideoAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le catalogue des vidéos de formation (31.10.2026), pour tout compte :
 * les vidéos destinées aux clients, et pour un vétérinaire vérifié celles
 * destinées aux vétérinaires. Ce qu'il a acheté reste à lui, même retiré.
 */
class VideoCatalogueController extends Controller
{
    public function __construct(private VideoAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $videos = Video::visibleTo(Video::query(), $user)
            ->with(['sellerUser', 'organisation', 'purchases' => fn ($purchase) => $purchase->where('user_id', $user->id)->whereNotNull('paid_at')])
            ->latest('published_at')->paginate(24);
        $organisations = Video::organisationsOf($user);

        return response()->json([
            'data' => $videos->getCollection()->map(fn (Video $video) => VideoResource::seenBy($video, $this->access->for($video, $user, $video->isOnSellerSide($user, $organisations)))->resolve($request))->all(),
            'meta' => ['current_page' => $videos->currentPage(), 'last_page' => $videos->lastPage(), 'total' => $videos->total()],
        ]);
    }

    /** Ses achats : à vie, même retirés de la vente. */
    public function purchased(Request $request): JsonResponse
    {
        $user = $request->user();
        $videos = Video::query()
            ->whereHas('purchases', fn ($purchase) => $purchase->where('user_id', $user->id)->whereNotNull('paid_at'))
            ->latest('id')
            ->get();

        return response()->json(['data' => $videos->map(fn (Video $video) => VideoResource::seenBy($video, 'owned')->resolve($request))->all()]);
    }

    public function show(Request $request, string $video): VideoResource|JsonResponse
    {
        $user = $request->user();
        $model = Video::query()->where('uuid', $video)->firstOrFail();
        $access = $this->access->for($model, $user, $model->isOnSellerSide($user));
        $view = VideoView::query()->where('video_id', $model->id)->where('user_id', $user->id)->first();
        $certificate = ViewingCertificate::query()->where('video_id', $model->id)->where('user_id', $user->id)->first();
        if ($access !== null) {
            return VideoResource::seenBy($model, $access, $access === 'seller')->withViewing($view, $certificate);
        }
        // Plus regardable (retirée, sortie de Plus) : qui l'a vue en entier y retrouve son certificat,
        // sans rien voir de ce que la vidéo est devenue depuis (titre figé du certificat, pas de prix ni de public)
        abort_if($model->published_at === null || ($view?->completed_at === null && $certificate === null), 404);

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
}
