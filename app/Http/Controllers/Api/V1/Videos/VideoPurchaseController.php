<?php

namespace App\Http\Controllers\Api\V1\Videos;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Videos\PurchaseRequest;
use App\Models\Video;
use App\Models\VideoPurchase;
use App\Services\Videos\VideoPurchases;
use App\Support\NativeApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Acheter une vidéo (31.10.2026) : sur le site seulement, définitif, payé au vendeur. */
class VideoPurchaseController extends Controller
{
    public function __construct(private VideoPurchases $purchases) {}

    public function store(PurchaseRequest $request, string $video): JsonResponse
    {
        NativeApp::refuse($request, 'L’achat d’une vidéo se fait sur le site de Veterinary, pas dans l’app iPhone.');
        $model = Video::query()->where('uuid', $video)->firstOrFail();

        return response()->json(['data' => $this->purchases->open($model, $request->user())]);
    }

    /** Au retour du paiement : la vidéo est à lui si c'est payé. */
    public function confirm(Request $request, string $video): JsonResponse
    {
        $model = Video::query()->where('uuid', $video)->firstOrFail();
        $purchase = VideoPurchase::query()->where('video_id', $model->id)->where('user_id', $request->user()->id)->firstOrFail();
        $purchase = $this->purchases->confirm($purchase);

        return response()->json(['data' => ['paid' => $purchase->paid_at !== null]]);
    }
}
