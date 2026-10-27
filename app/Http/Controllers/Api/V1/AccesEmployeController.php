<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Auth\RevocationJetons;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EmployeResource;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Suspension et rétablissement d'accès (écran Organisation). Un
 * employé suspendu ne compte plus dans la limite d'employés actifs de
 * la formule — le rétablissement la revérifie. La suspension coupe
 * l'accès API immédiatement (middleware clinique).
 */
class AccesEmployeController extends Controller
{
    public function suspendre(Request $request, int $employe): EmployeResource
    {
        $fiche = EquipeEmployeController::employeDeLOrganisation($employe);

        $this->authorize('retirerAcces', $fiche);

        $fiche->update(['acces_suspendu' => true]);
        app(RevocationJetons::class)->pourEmploye($fiche);

        return new EmployeResource($fiche);
    }

    public function retablir(Request $request, int $employe): EmployeResource
    {
        $fiche = EquipeEmployeController::employeDeLOrganisation($employe);

        $this->authorize('rendreAcces', $fiche);

        // Compte supprimé par son titulaire (trace sans connexion, 28.10.2026)
        if ($fiche->user?->efface_le !== null) {
            throw ValidationException::withMessages(['acces' => 'Ce compte a été supprimé : son accès ne peut pas être rétabli.']);
        }

        $organisation = app(ContexteClinique::class)->clinique()->societe->organisation;

        if ($fiche->acces_suspendu && $organisation->limiteEmployesAtteinte()) {
            $limite = $organisation->plan->limites()['employes'];

            throw ValidationException::withMessages([
                'acces' => "Limite de {$limite} employés actifs atteinte, formule supérieure requise.",
            ]);
        }

        $fiche->update(['acces_suspendu' => false]);

        return new EmployeResource($fiche);
    }
}
