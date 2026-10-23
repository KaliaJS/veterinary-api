<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\StatutAnalyse;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AnalyseResource;
use App\Models\Analyse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Cycle d'une demande d'analyses, un cran à la fois, sans retour
 * (LAB_ADVANCE du front) : réception (attente → cours), saisie
 * terminée (cours → valid — bloquée tant qu'un paramètre attend sa
 * valeur), validation signée (valid → ok — le signataire est l'employé
 * du jeton, les résultats sont figés).
 */
class CycleAnalyseController extends Controller
{
    public function __invoke(Analyse $analyse): AnalyseResource
    {
        $this->authorize('update', $analyse);

        $suivant = $analyse->statut->suivant();

        if ($suivant === null) {
            throw ValidationException::withMessages(['statut' => 'Une analyse validée ne change plus de statut.']);
        }

        // La validation est une signature : droit `valider_analyses`
        if ($suivant === StatutAnalyse::Valide) {
            Gate::authorize(DomainePermission::ValiderAnalyses->value);
        }

        if ($suivant === StatutAnalyse::AValider && $analyse->resultats()->whereNull('valeur')->exists()) {
            throw ValidationException::withMessages(['resultats' => 'Saisie incomplète — chaque paramètre demandé attend une valeur.']);
        }

        $analyse->update([
            'statut' => $suivant,
            ...$suivant === StatutAnalyse::Valide ? [
                'valide_par_id' => app(ContexteClinique::class)->employe()?->id,
                'valide_le' => now(),
            ] : [],
        ]);

        return new AnalyseResource($analyse->load(AnalyseController::relations()));
    }
}
