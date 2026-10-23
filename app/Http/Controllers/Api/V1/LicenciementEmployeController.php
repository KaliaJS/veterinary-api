<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\RevocationJetons;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\LicencierEmployeRequest;
use App\Http\Resources\V1\EmployeResource;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Licenciement (écran Organisation — docs/data-model.md §2) : un état
 * sur la fiche, jamais une suppression. La notification pose la date du
 * jour et la fin de contrat ; la libération immédiate suspend l'accès.
 * L'annulation efface l'état — l'accès reste tel quel (le rétablir est
 * un geste distinct, soumis au quota).
 */
class LicenciementEmployeController extends Controller
{
    public function licencier(LicencierEmployeRequest $request, int $employe): EmployeResource
    {
        $fiche = EquipeEmployeController::employeDeLOrganisation($employe);

        $this->authorize('retirerAcces', $fiche);

        if ($fiche->estLicencie()) {
            throw ValidationException::withMessages(['licenciement' => 'Un licenciement est déjà notifié — annulez-le d’abord.']);
        }

        $fiche->update([
            'licenciement_notifie_le' => today(),
            'fin_contrat_le' => $request->validated('fin_contrat_le'),
            'licenciement_motif' => $request->validated('motif'),
            ...($request->boolean('revoquer_acces') ? ['acces_suspendu' => true] : []),
        ]);
        if ($request->boolean('revoquer_acces')) {
            app(RevocationJetons::class)->pourEmploye($fiche);
        }

        return new EmployeResource($fiche);
    }

    public function annuler(Request $request, int $employe): EmployeResource
    {
        $fiche = EquipeEmployeController::employeDeLOrganisation($employe);

        $this->authorize('update', $fiche);

        if (! $fiche->estLicencie()) {
            throw ValidationException::withMessages(['licenciement' => 'Aucun licenciement notifié pour cette fiche.']);
        }

        $fiche->update([
            'licenciement_notifie_le' => null,
            'fin_contrat_le' => null,
            'licenciement_motif' => null,
        ]);

        return new EmployeResource($fiche);
    }
}
