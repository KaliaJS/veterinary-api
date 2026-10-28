<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\FormuleSaas;
use App\Enums\TypePack;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrganisationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * L'organisation du jeton — jamais une autre : il n'y a pas de
 * binding, la résolution part toujours du contexte.
 */
class OrganisationController extends Controller
{
    public function afficher(Request $request): OrganisationResource
    {
        return new OrganisationResource(
            app(ContexteClinique::class)->clinique()->societe->organisation,
        );
    }

    /**
     * Catalogue des formules et des packs (28.10.2026) : prix, limites
     * (null : illimité) et paliers, tels que le serveur les facture. Le
     * site n'en garde que les textes de présentation.
     */
    public function offres(): JsonResponse
    {
        return response()->json(['data' => [
            'formules' => array_map(fn (FormuleSaas $formule): array => [
                'code' => $formule->value,
                'nom' => $formule->nom(),
                'prix_mensuel' => $formule->prixMensuel(),
                'limites' => $formule->limites(),
            ], FormuleSaas::cases()),
            'packs' => array_map(fn (TypePack $pack): array => [
                'type' => $pack->value,
                'paliers' => array_map(fn (array $palier): array => ['quantite' => $palier[0], 'prix' => $palier[1]], $pack->paliers()),
            ], TypePack::cases()),
        ]]);
    }
}
