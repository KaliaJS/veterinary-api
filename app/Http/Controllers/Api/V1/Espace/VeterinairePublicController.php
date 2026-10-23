<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Enums\Specialite;
use App\Enums\TypeParcours;
use App\Http\Controllers\Controller;
use App\Models\Clinique;
use App\Models\Employe;
use App\Models\ParcoursVeterinaire;
use App\Models\SpecialiteVeterinaire;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Fiche publique d'un vétérinaire dans l'annuaire : seulement s'il est
 * vérifié par la plateforme et qu'une clinique de l'annuaire le montre.
 * Où il exerce (ses cliniques sur Veterinary, avec l'année d'arrivée),
 * puis ce qu'il écrit lui-même : présentation, langues, postes et
 * formations. Ses postes sur Veterinary passés et présents viennent des
 * fiches employé : ils ne se déclarent pas.
 */
class VeterinairePublicController extends Controller
{
    public function afficher(int $veterinaire): JsonResponse
    {
        $compte = User::query()->with('specialites')->find($veterinaire);
        $postes = $compte?->estVerifie() ? $compte->postesVisibles() : collect();
        abort_if($postes->isEmpty(), 404, 'Ce vétérinaire n’a pas de fiche publique.');

        $anciens = Employe::withoutGlobalScopes()
            ->where('user_id', $compte->id)
            ->whereNotNull('fin_contrat_le')
            ->whereDate('fin_contrat_le', '<', aujourdhui())
            ->with('organisation')
            ->get()
            ->filter(fn (Employe $fiche): bool => $fiche->estVeterinaire());

        $photo = Employe::withoutGlobalScopes()->where('user_id', $compte->id)->whereNotNull('photo_chemin')->first()?->photoUrl();

        // Sur Veterinary : les postes actuels (leurs cliniques), puis les anciens
        $surVeterinary = [
            ...$postes->map(fn (Employe $fiche): array => [
                'type' => TypeParcours::Experience->value,
                'titre' => $fiche->role_libelle,
                'lieu' => $fiche->cliniques->pluck('nom')->join(', '),
                'ville' => $fiche->cliniques->count() === 1 ? $fiche->cliniques->first()->ville : null,
                'annee_debut' => $fiche->entree_le?->year,
                'annee_fin' => null,
                'sur_veterinary' => true,
            ])->all(),
            ...$anciens->map(fn (Employe $fiche): array => [
                'type' => TypeParcours::Experience->value,
                'titre' => $fiche->role_libelle,
                'lieu' => $fiche->organisation?->nom,
                'ville' => null,
                'annee_debut' => $fiche->entree_le?->year,
                'annee_fin' => $fiche->fin_contrat_le?->year,
                'sur_veterinary' => true,
            ])->all(),
        ];
        $declares = $compte->parcours()->get()->map(fn (ParcoursVeterinaire $ligne): array => [...$ligne->versTableau(), 'sur_veterinary' => false]);

        return response()->json(['data' => [
            'id' => $compte->id,
            'nom' => $postes->first()->nom,
            'photo_url' => $photo,
            'verifie' => true,
            'specialites' => $compte->specialites
                ->sortBy(fn (SpecialiteVeterinaire $ligne): int => (int) array_search($ligne->specialite, Specialite::cases(), true))
                ->map(fn (SpecialiteVeterinaire $ligne): string => $ligne->specialite->value)
                ->values()->all(),
            'langues' => $compte->langues ?? [],
            'presentation' => $compte->presentation,
            // Où il exerce aujourd'hui : chaque clinique de l'annuaire qui le montre
            'exerce' => $postes->flatMap(fn (Employe $fiche) => $fiche->cliniques->map(fn (Clinique $clinique): array => [
                'id' => $clinique->id,
                'nom' => $clinique->nom,
                'ville' => $clinique->ville,
                'depuis' => $fiche->entree_le?->year,
            ]))->unique('id')->values()->all(),
            'experiences' => [
                ...$surVeterinary,
                ...$declares->where('type', TypeParcours::Experience->value)->values()->all(),
            ],
            'formations' => $declares->where('type', TypeParcours::Formation->value)->values()->all(),
        ]]);
    }
}
