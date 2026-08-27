<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PrestationResource;
use App\Models\Prestation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Catalogue d'actes de l'organisation — un acte, un prix (décision
 * ouverte de docs/data-model.md tranchée).
 */
class PrestationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Prestation::class);

        return PrestationResource::collection(
            Prestation::query()->orderBy('nom')->paginate($this->parPage($request, 100)),
        );
    }
}
