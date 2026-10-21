<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\ModifierSpecialitesRequest;
use App\Http\Resources\V1\EmployeResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Spécialités d'un vétérinaire, cochées par l'administrateur sur sa
 * fiche (écrans Équipe et Organisation). Elles font celles des
 * cliniques où il travaille, dans l'annuaire public.
 */
class SpecialitesEmployeController extends Controller
{
    public function __invoke(ModifierSpecialitesRequest $request, int $employe): EmployeResource
    {
        $fiche = EquipeEmployeController::employeDeLOrganisation($employe);
        $this->authorize('update', $fiche);

        if (! $fiche->estVeterinaire()) {
            throw ValidationException::withMessages([
                'specialites' => 'Les spécialités sont celles des vétérinaires.',
            ]);
        }

        $voulues = $request->validated('specialites');
        DB::transaction(function () use ($fiche, $voulues): void {
            $fiche->specialites()->whereNotIn('specialite', $voulues)->delete();
            foreach ($voulues as $specialite) {
                $fiche->specialites()->firstOrCreate(['specialite' => $specialite]);
            }
        });

        return new EmployeResource($fiche->load(['cliniques', 'lignePlanning', 'specialites']));
    }
}
