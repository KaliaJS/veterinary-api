<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employe;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Embauche, étape 1 : la personne donne le code employé de son compte
 * Veterinary (Réglages, Profil) ; l'administrateur le saisit et voit à
 * qui il appartient avant de rédiger le contrat. Le code ne révèle que le
 * nom et l'adresse du compte, à qui la personne l'a confié.
 */
class CompteEmployeController extends Controller
{
    public function __invoke(string $code): JsonResponse
    {
        $this->authorize('create', Employe::class);

        $compte = self::compteDuCode($code);
        $fiche = Employe::withoutGlobalScope('clinique')->where('user_id', $compte->id)->first();

        return response()->json(['data' => [
            'nom' => $compte->name,
            'email' => $compte->email,
            // Déjà dans l'organisation : on lui ajoute des cliniques depuis sa fiche
            'employe_id' => $fiche?->id,
        ]]);
    }

    /** @throws ValidationException */
    public static function compteDuCode(string $saisi): User
    {
        $code = User::normaliserCodeEmploye($saisi);

        return ($code ? User::query()->where('code_employe', $code)->first() : null)
            ?? throw ValidationException::withMessages(['code' => 'Aucun compte Veterinary ne porte ce code.']);
    }
}
