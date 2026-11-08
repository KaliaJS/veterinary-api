<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Services\Workday\UnfinishedWork;
use Illuminate\Http\JsonResponse;

/**
 * « À terminer » de la personne du jeton (08.11.2026) : les vétérinaires
 * seulement, leur propre travail (choix de Jim), avec le droit Patients
 * (route). Les autres reçoivent `is_concerned: false`, sans rien d'autre.
 */
class UnfinishedWorkController extends Controller
{
    public function __construct(private UnfinishedWork $work, private ContexteClinique $contexte) {}

    public function __invoke(): JsonResponse
    {
        $employe = $this->contexte->employe();
        if ($employe === null || ! $employe->estVeterinaire()) {
            return response()->json(['data' => ['is_concerned' => false]]);
        }

        return response()->json(['data' => $this->work->for(
            $employe,
            $this->contexte->clinique(),
            fn (DomainePermission $domaine): bool => $this->contexte->permet($domaine),
        )]);
    }
}
