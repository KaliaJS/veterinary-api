<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EtatDent;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\RemplacerReleveDentaireRequest;
use App\Models\Patient;
use App\Models\ReleveDentaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Odontogramme Triadan — une map « n° de dent → état », l'absence de
 * clé vaut sain. Le PUT remplace le relevé entier (parité avec la
 * carte dentaire du front, qui envoie l'état complet).
 */
class ReleveDentaireController extends Controller
{
    public function afficher(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        return $this->reponse($patient);
    }

    public function remplacer(RemplacerReleveDentaireRequest $request, Patient $patient): JsonResponse
    {
        $this->authorize('update', $patient);

        /** @var array<string, string> $dents */
        $dents = $request->validated('dents');

        DB::transaction(function () use ($patient, $dents): void {
            $patient->relevesDentaires()->delete();

            foreach ($dents as $dent => $etat) {
                $patient->relevesDentaires()->create([
                    'dent' => (int) $dent,
                    'etat' => EtatDent::from($etat),
                ]);
            }
        });

        return $this->reponse($patient);
    }

    private function reponse(Patient $patient): JsonResponse
    {
        $dents = $patient->relevesDentaires()
            ->orderBy('dent')
            ->get()
            ->mapWithKeys(fn (ReleveDentaire $releve) => [(string) $releve->dent => $releve->etat]);

        return response()->json(['data' => ['dents' => $dents]]);
    }
}
