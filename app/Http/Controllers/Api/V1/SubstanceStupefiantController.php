<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProduitResource;
use App\Models\MouvementStupefiant;
use App\Models\Produit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Marquer un produit du catalogue comme stupéfiant, avec son tableau
 * (a : méthadone, buprénorphine, fentanyl, morphine… ; b : diazépam,
 * midazolam, pentobarbital… ; OTStup-DFI), ou le retirer (01.11.2026 :
 * jusque-là, seule la démo en avait). Un produit qui a déjà des lignes au
 * registre reste stupéfiant : son registre se garde dix ans.
 */
class SubstanceStupefiantController extends Controller
{
    public function __invoke(Request $request, Produit $produit): ProduitResource
    {
        Gate::authorize(DomainePermission::Stupefiants->value);

        $donnees = $request->validate([
            'est_stupefiant' => ['required', 'boolean'],
            'tableau' => [Rule::requiredIf(fn (): bool => $request->boolean('est_stupefiant')), 'nullable', Rule::in(['a', 'b'])],
        ], [
            'tableau.required' => 'Choisissez le tableau : a ou b.',
        ]);

        $aDesLignes = MouvementStupefiant::withoutGlobalScopes()->where('produit_id', $produit->id)->exists();
        if (! $donnees['est_stupefiant'] && $aDesLignes) {
            throw ValidationException::withMessages(['est_stupefiant' => 'Ce produit a déjà des lignes au registre : il reste stupéfiant, son registre se garde dix ans.']);
        }

        $produit->update([
            'est_stupefiant' => $donnees['est_stupefiant'],
            'tableau_stupefiant' => $donnees['est_stupefiant'] ? $donnees['tableau'] : null,
        ]);

        return new ProduitResource($produit);
    }
}
