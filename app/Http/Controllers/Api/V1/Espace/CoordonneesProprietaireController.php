<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\ModifierCoordonneesRequest;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Mes coordonnées (proto) : prénom, nom, téléphone, adresse. Lues sur
 * la première fiche client du compte (sinon sur le compte) ; écrites
 * sur le compte (nom) ET sur toutes ses fiches — un propriétaire
 * client de deux organisations n'a qu'une adresse. L'e-mail est
 * l'identifiant de connexion : il ne se modifie pas ici.
 */
class CoordonneesProprietaireController extends Controller
{
    public function afficher(ContexteProprietaire $contexte): JsonResponse
    {
        return response()->json(['data' => $this->coordonnees($contexte)]);
    }

    public function modifier(ModifierCoordonneesRequest $request, ContexteProprietaire $contexte): JsonResponse
    {
        $donnees = $request->validated();
        $user = $contexte->user();

        DB::transaction(function () use ($donnees, $contexte, $user): void {
            $user->forceFill(['name' => trim($donnees['prenom'].' '.$donnees['nom'])])->save();

            $contexte->clients()->each(fn (Client $fiche) => $fiche->update([
                'prenom' => $donnees['prenom'],
                'nom' => $donnees['nom'],
                'tel' => $donnees['tel'] ?? null,
                'rue' => $donnees['rue'] ?? null,
                'npa' => $donnees['npa'] ?? null,
                'localite' => $donnees['localite'] ?? null,
            ]));
        });

        return response()->json(['data' => $this->coordonnees($contexte)]);
    }

    /** @return array<string, mixed> */
    private function coordonnees(ContexteProprietaire $contexte): array
    {
        $user = $contexte->user()->fresh();
        $fiche = $contexte->clients()->first()?->fresh();

        return [
            'prenom' => $fiche->prenom ?? $user->prenom(),
            'nom' => $fiche->nom ?? $user->nomFamille(),
            'tel' => $fiche?->tel,
            'email' => $user->email,
            'rue' => $fiche?->rue,
            'npa' => $fiche?->npa,
            'localite' => $fiche?->localite,
            'nombre_fiches' => $contexte->clients()->count(),
        ];
    }
}
