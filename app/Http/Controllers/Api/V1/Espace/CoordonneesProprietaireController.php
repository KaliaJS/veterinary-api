<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Espace\ModifierCoordonneesRequest;
use App\Models\Client;
use App\Support\Telephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            // Gardées sur le compte aussi : avant toute fiche de clinique (28.10.2026)
            $user->forceFill([
                'name' => trim($donnees['prenom'].' '.$donnees['nom']),
                'tel' => $donnees['tel'] ?? null,
                'rue' => $donnees['rue'] ?? null,
                'npa' => $donnees['npa'] ?? null,
                'localite' => $donnees['localite'] ?? null,
            ])->save();

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

    /**
     * Le mobile seul, pour les SMS (28.10.2026) : un compte qui vient de
     * s'inscrire n'a qu'un nom provisoire, il ne doit pas le remplir pour
     * recevoir ses rappels par SMS. Gardé sur le compte et sur ses fiches.
     */
    public function mobile(Request $request, ContexteProprietaire $contexte): JsonResponse
    {
        $tel = trim((string) $request->validate(['tel' => ['required', 'string', 'max:40', function (string $attribut, mixed $valeur, \Closure $echec): void {
            if (Telephone::mobile((string) $valeur) === null) {
                $echec('Ce numéro n’est pas un mobile : les SMS partent vers un numéro en 07x, ou étranger avec son indicatif.');
            }
        }]])['tel']);

        DB::transaction(function () use ($contexte, $tel): void {
            $contexte->user()->forceFill(['tel' => $tel])->save();
            $contexte->clients()->each(fn (Client $fiche) => $fiche->update(['tel' => $tel]));
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
            'tel' => $fiche->tel ?? $user->tel,
            'email' => $user->email,
            'rue' => $fiche->rue ?? $user->rue,
            'npa' => $fiche->npa ?? $user->npa,
            'localite' => $fiche->localite ?? $user->localite,
            'nombre_fiches' => $contexte->clients()->count(),
        ];
    }
}
