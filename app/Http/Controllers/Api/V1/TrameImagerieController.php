<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ModaliteImagerie;
use App\Http\Controllers\Controller;
use App\Models\ExamenImagerie;
use Illuminate\Http\JsonResponse;

/**
 * Trames de compte rendu par modalité (REPORT_TEMPLATES du front) —
 * référentiel applicatif porté par l'enum, servi tel quel.
 */
class TrameImagerieController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', ExamenImagerie::class);

        $trames = [];
        foreach (ModaliteImagerie::cases() as $modalite) {
            $trames[$modalite->value] = $modalite->trame();
        }

        return response()->json(['data' => $trames]);
    }
}
