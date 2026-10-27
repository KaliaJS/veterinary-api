<?php

namespace App\Http\Controllers\Api\V1\Plateforme;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Plateforme\FonctionsEssai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fonctions en essai (espace Plateforme, 27.10.2026) : qui a chaque
 * fonction, l'activer ou la retirer pour un compte, une organisation ou
 * tout le monde. Sans redéployer.
 */
class FonctionEssaiPlateformeController extends Controller
{
    public function __construct(private FonctionsEssai $fonctions) {}

    public function index(): JsonResponse
    {
        $liste = [];
        foreach ($this->fonctions->liste() as $nom => $libelle) {
            $liste[] = ['nom' => $nom, 'libelle' => $libelle, ...$this->fonctions->porteurs($nom)];
        }

        return response()->json(['data' => $liste]);
    }

    public function activer(Request $request, string $nom): JsonResponse
    {
        abort_unless($this->fonctions->existe($nom), 404, 'Fonction en essai inconnue.');
        $this->fonctions->activer($nom, $this->portee($request));

        return $this->fiche($nom);
    }

    public function retirer(Request $request, string $nom): JsonResponse
    {
        abort_unless($this->fonctions->existe($nom), 404, 'Fonction en essai inconnue.');
        $this->fonctions->retirer($nom, $this->portee($request));

        return $this->fiche($nom);
    }

    private function fiche(string $nom): JsonResponse
    {
        return response()->json(['data' => ['nom' => $nom, 'libelle' => $this->fonctions->liste()[$nom], ...$this->fonctions->porteurs($nom)]]);
    }

    /** Un compte (par son adresse), une organisation ou tout le monde. */
    private function portee(Request $request): User|Organisation|string
    {
        $donnees = $request->validate([
            'portee' => ['required', Rule::in(['compte', 'organisation', 'tous'])],
            'email' => ['required_if:portee,compte', 'nullable', 'email'],
            'organisation_id' => ['required_if:portee,organisation', 'nullable', 'integer', 'exists:organisations,id'],
        ], [
            'portee.in' => 'Choisissez un compte, une organisation ou tout le monde.',
            'email.required_if' => 'Indiquez l’adresse du compte.',
            'organisation_id.required_if' => 'Choisissez une organisation.',
            'organisation_id.exists' => 'Cette organisation n’existe pas.',
        ]);

        return match ($donnees['portee']) {
            'tous' => FonctionsEssai::TOUS,
            'organisation' => Organisation::query()->findOrFail($donnees['organisation_id']),
            default => User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $donnees['email']))])->first()
                ?? throw ValidationException::withMessages(['email' => 'Aucun compte à cette adresse.']),
        };
    }
}
