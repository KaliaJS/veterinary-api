<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;

/**
 * Les vétérinaires à choisir comme référent d'un animal (07.11.2026) : ceux
 * de l'organisation, de toutes ses cliniques, accès suspendus exclus, pour
 * qui a le droit Patients. Rien de sensible : le nom et les cliniques.
 */
class ReferringVetController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);

        $vets = Employe::query()
            ->withoutGlobalScope('clinique')
            ->with(['cliniques' => fn ($q) => $q->withoutGlobalScopes()->select('cliniques.id', 'cliniques.nom')->orderBy('cliniques.nom')])
            ->where('role_libelle', 'like', 'Vétérinaire%')
            ->where('acces_suspendu', false)
            ->orderBy('nom')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $vets->map(fn (Employe $e): array => [
                'id' => $e->id,
                'nom' => $e->nom,
                'cliniques' => $e->cliniques->map(fn (Clinique $c): array => ['id' => $c->id, 'nom' => $c->nom])->values()->all(),
            ])->values()->all(),
        ]);
    }
}
