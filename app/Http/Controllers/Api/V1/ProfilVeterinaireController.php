<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Specialite;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\ModifierProfilVeterinaireRequest;
use App\Models\SpecialiteVeterinaire;
use App\Models\User;
use App\Services\Inscription\VerificationVeterinaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Profil vétérinaire de son propre compte (Réglages, Profil) : son GLN,
 * cherché dans MedReg au même nom, et ses spécialités, qu'il gère
 * lui-même et qui le suivent dans toutes ses cliniques. Un GLN est
 * public : le registre écarte les fautes et les inventions, il ne
 * prouve pas l'identité (vérification manuelle de la plateforme, à
 * part).
 */
class ProfilVeterinaireController extends Controller
{
    public function __construct(private VerificationVeterinaire $verification) {}

    public function afficher(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profil($request->user())]);
    }

    public function modifier(ModifierProfilVeterinaireRequest $request): JsonResponse
    {
        $compte = $request->user();
        $gln = $request->validated('gln');
        $registre = null;

        if ($gln !== $compte->gln) {
            if ($gln === null) {
                $compte->forceFill(['gln' => null, 'medreg_id' => null, 'gln_verifie_le' => null])->save();
            } else {
                $resultat = $this->verification->verifier($gln, $compte->prenom(), $compte->nomFamille(), $compte);
                if ($resultat->raison === 'gln_deja_utilise') {
                    throw ValidationException::withMessages(['gln' => 'Ce GLN est déjà celui d’un autre compte. Écrivez-nous si c’est le vôtre.']);
                }
                // Gardé dans tous les cas ; « trouvé au registre » seulement si MedReg confirme le nom
                $compte->forceFill([
                    'gln' => $gln,
                    'medreg_id' => $resultat->fiche?->id,
                    'gln_verifie_le' => $resultat->raison === null ? now() : null,
                ])->save();
                $registre = $resultat->raison ?? 'trouve';
            }
        }

        $voulues = $request->validated('specialites');
        DB::transaction(function () use ($compte, $voulues): void {
            $compte->specialites()->whereNotIn('specialite', $voulues)->delete();
            foreach ($voulues as $specialite) {
                $compte->specialites()->firstOrCreate(['specialite' => $specialite]);
            }
        });

        return response()->json(['data' => [...$this->profil($compte->fresh()), 'registre_resultat' => $registre]]);
    }

    /** @return array<string, mixed> */
    private function profil(User $compte): array
    {
        return [
            'gln' => $compte->gln,
            // Trouvé dans MedReg au nom du compte
            'gln_au_registre' => $compte->gln_verifie_le !== null,
            'specialites' => $compte->specialites()->get()
                ->sortBy(fn (SpecialiteVeterinaire $ligne): int => array_search($ligne->specialite, Specialite::cases(), true))
                ->map(fn (SpecialiteVeterinaire $ligne): string => $ligne->specialite->value)
                ->values()->all(),
        ];
    }
}
