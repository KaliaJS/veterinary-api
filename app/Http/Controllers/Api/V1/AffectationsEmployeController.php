<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\AffecterEmployeRequest;
use App\Http\Resources\V1\EmployeResource;
use App\Models\Clinique;
use App\Models\RendezVous;
use Illuminate\Validation\ValidationException;

/**
 * Cliniques où travaille un employé, choisies par l'administrateur :
 * toutes ou quelques-unes, jamais automatiquement. Retirer une clinique
 * ferme à la personne cette clinique dès sa requête suivante ; c'est
 * refusé tant qu'elle y a des rendez-vous à venir (à déplacer d'abord),
 * et pour la clinique où l'on est soi-même connecté.
 */
class AffectationsEmployeController extends Controller
{
    public function __invoke(AffecterEmployeRequest $request, int $employe, ContexteClinique $contexte): EmployeResource
    {
        $fiche = EquipeEmployeController::employeDeLOrganisation($employe);
        $this->authorize('update', $fiche);

        $voulues = $request->boolean('toutes_les_cliniques')
            ? $contexte->clinique()->societe->organisation->cliniques()->pluck('cliniques.id')->all()
            : array_map('intval', $request->validated('cliniques'));
        $retirees = array_diff($fiche->cliniques()->pluck('cliniques.id')->all(), $voulues);

        if ($fiche->id === $contexte->employe()->id && in_array($contexte->cliniqueId(), $retirees, true)) {
            throw ValidationException::withMessages([
                'cliniques' => 'Vous ne pouvez pas vous retirer de la clinique où vous êtes connecté.',
            ]);
        }

        foreach ($retirees as $cliniqueId) {
            $aVenir = RendezVous::withoutGlobalScope('clinique')
                ->where('clinique_id', $cliniqueId)
                ->whereHas('lignePlanning', fn ($lignes) => $lignes->withoutGlobalScope('clinique')->where('employe_id', $fiche->id))
                ->where('status', '!=', StatutRendezVous::Annule)
                ->where('starts_at', '>', now())
                ->count();

            if ($aVenir > 0) {
                $clinique = Clinique::find($cliniqueId)->nom;
                throw ValidationException::withMessages([
                    'cliniques' => "{$fiche->nom} a encore {$aVenir} rendez-vous à venir à {$clinique}. Déplacez-les avant de retirer cette clinique.",
                ]);
            }
        }

        $fiche->cliniques()->sync($voulues);

        return new EmployeResource($fiche->load(['cliniques', 'lignePlanning']));
    }
}
