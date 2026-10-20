<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PrestationResource;
use App\Models\Prestation;
use App\Services\PrestationsParDefaut;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Catalogue d'actes de l'organisation, géré par ses administrateurs
 * (Réglages › Prestations et tarifs) : un acte, un prix. Les actes de
 * départ sont lus par leur code (types de rendez-vous, fin de
 * consultation, séjours d'hospitalisation) : ils se renomment et
 * changent de prix, ils ne se suppriment pas. Les lignes de facture
 * recopient nom et prix : supprimer un acte ne touche aucune facture.
 */
class OrganisationPrestationController extends Controller
{
    public function store(Request $request): PrestationResource
    {
        $donnees = $this->valider($request);

        $base = Str::limit(Str::slug($donnees['nom'], '_'), 16, '') ?: 'acte';
        $code = $base;
        for ($n = 2; Prestation::query()->where('code', $code)->exists(); $n++) {
            $code = $base.'_'.$n;
        }

        return new PrestationResource(Prestation::create([...$donnees, 'code' => $code]));
    }

    public function update(Request $request, Prestation $prestation): PrestationResource
    {
        $prestation->update($this->valider($request));

        return new PrestationResource($prestation);
    }

    public function destroy(Prestation $prestation): Response
    {
        if (array_key_exists($prestation->code, PrestationsParDefaut::CATALOGUE)) {
            throw ValidationException::withMessages([
                'prestation' => 'Cet acte sert au planning, à la fin de consultation ou à l’hospitalisation : changez son nom ou son prix, il ne se supprime pas.',
            ]);
        }

        $prestation->delete();

        return response()->noContent();
    }

    /** @return array{nom: string, prix: float} */
    private function valider(Request $request): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'prix' => ['required', 'numeric', 'min:0', 'max:99999.99'],
        ]);
    }
}
