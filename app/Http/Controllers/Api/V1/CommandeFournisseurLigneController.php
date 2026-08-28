<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutCommandeFournisseur;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Stocks\EnregistrerLigneCommandeRequest;
use App\Http\Requests\V1\Stocks\ModifierLigneCommandeRequest;
use App\Http\Resources\V1\CommandeFournisseurResource;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\Produit;
use Illuminate\Validation\ValidationException;

/**
 * Lignes d'un BROUILLON de commande fournisseur : ajout, quantité,
 * retrait — avant envoi seulement, une commande envoyée est figée
 * (le fournisseur l'a reçue). Les lignes se résolvent via leur
 * commande (aucun scope propre) ; le prix d'achat est figé à l'ajout.
 * Chaque geste renvoie la commande complète.
 */
class CommandeFournisseurLigneController extends Controller
{
    public function store(EnregistrerLigneCommandeRequest $request, int $commande): CommandeFournisseurResource
    {
        $commande = $this->brouillonModifiable($commande);

        $produit = Produit::findOrFail($request->validated('produit_id'));

        if ($commande->lignes()->where('produit_id', $produit->id)->exists()) {
            throw ValidationException::withMessages([
                'produit_id' => 'Cet article est déjà sur la commande — modifiez sa ligne.',
            ]);
        }

        $commande->lignes()->create([
            'produit_id' => $produit->id,
            'quantite' => $request->validated('quantite'),
            'cout' => $produit->cout,
        ]);

        return new CommandeFournisseurResource($commande->load('lignes'));
    }

    public function update(ModifierLigneCommandeRequest $request, int $commande, int $ligne): CommandeFournisseurResource
    {
        $commande = $this->brouillonModifiable($commande);

        $this->ligneDe($commande, $ligne)->update(['quantite' => $request->validated('quantite')]);

        return new CommandeFournisseurResource($commande->load('lignes'));
    }

    public function destroy(int $commande, int $ligne): CommandeFournisseurResource
    {
        $commande = $this->brouillonModifiable($commande);

        $this->ligneDe($commande, $ligne)->delete();

        return new CommandeFournisseurResource($commande->load('lignes'));
    }

    /** @throws ValidationException si la commande n'est plus un brouillon */
    private function brouillonModifiable(int $id): CommandeFournisseur
    {
        $commande = CommandeFournisseurController::commandeDeLOrganisation($id);

        $this->authorize('update', $commande);

        if ($commande->statut !== StatutCommandeFournisseur::Brouillon) {
            throw ValidationException::withMessages([
                'statut' => 'Seules les lignes d’un brouillon se modifient.',
            ]);
        }

        return $commande;
    }

    private function ligneDe(CommandeFournisseur $commande, int $id): CommandeFournisseurLigne
    {
        return $commande->lignes()->findOrFail($id);
    }
}
