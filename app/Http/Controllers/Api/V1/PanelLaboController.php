<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PanelLaboResource;
use App\Models\Analyse;
use App\Models\PanelLabo;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Référentiel des panels d'analyses de l'organisation (LAB_PANELS du
 * front) — la liste cochable du formulaire de demande, avec tubes et
 * bornes de référence.
 */
class PanelLaboController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Analyse::class);

        return PanelLaboResource::collection(
            PanelLabo::with('parametres')->orderBy('id')->get(),
        );
    }
}
