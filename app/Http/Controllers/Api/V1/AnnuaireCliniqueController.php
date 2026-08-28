<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AnnuaireCliniqueResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Annuaire des cliniques de l'organisation du jeton — le minimum
 * d'affichage (id, nom, boxes) pour nommer les colonnes de la
 * ventilation des stocks, les rattachements, les boxes : ouvert à tout
 * employé authentifié, sans le domaine `organisation` qui, lui, garde
 * les données de gestion (adresse, société, équipe, horaires).
 */
class AnnuaireCliniqueController extends Controller
{
    public function __invoke(ContexteClinique $contexte): AnonymousResourceCollection
    {
        $cliniques = $contexte->clinique()->societe->organisation
            ->cliniques()
            ->orderBy('cliniques.id')
            ->get();

        return AnnuaireCliniqueResource::collection($cliniques);
    }
}
