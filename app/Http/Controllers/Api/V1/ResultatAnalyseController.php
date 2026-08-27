<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DrapeauResultatLabo;
use App\Enums\StatutAnalyse;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Laboratoire\SaisirResultatsRequest;
use App\Http\Resources\V1\AnalyseResource;
use App\Models\Analyse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saisie des résultats — pendant l'analyse (`cours`) ou la relecture
 * (`valid`) seulement : rien avant la réception, plus rien après la
 * signature. Le drapeau H/L est dérivé des bornes instantanées, jamais
 * saisi. Les lignes se résolvent via l'analyse, jamais par binding
 * direct.
 */
class ResultatAnalyseController extends Controller
{
    public function __invoke(SaisirResultatsRequest $request, Analyse $analyse): AnalyseResource
    {
        $this->authorize('update', $analyse);

        if (! in_array($analyse->statut, [StatutAnalyse::Cours, StatutAnalyse::AValider], true)) {
            throw ValidationException::withMessages([
                'statut' => $analyse->estValidee()
                    ? 'Les résultats d\'une analyse validée sont figés.'
                    : 'La demande n\'est pas encore réceptionnée au laboratoire.',
            ]);
        }

        DB::transaction(function () use ($request, $analyse): void {
            foreach ($request->validated('resultats') as $saisie) {
                $resultat = $analyse->resultats()->findOrFail($saisie['id']);

                $resultat->update([
                    'valeur' => $saisie['valeur'],
                    'drapeau' => $saisie['valeur'] === null ? null : DrapeauResultatLabo::depuis(
                        (float) $saisie['valeur'],
                        (float) $resultat->borne_basse,
                        (float) $resultat->borne_haute,
                    ),
                ]);
            }
        });

        return new AnalyseResource($analyse->load(AnalyseController::relations()));
    }
}
