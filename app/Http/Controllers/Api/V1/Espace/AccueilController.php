<?php

namespace App\Http\Controllers\Api\V1\Espace;

use App\Auth\ContexteProprietaire;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Espace\AccueilProprietaireResource;

/**
 * GET /espace/accueil — tableau de bord du propriétaire, toutes
 * cliniques confondues (contexte posé par le middleware `proprietaire`).
 */
class AccueilController extends Controller
{
    public function __invoke(ContexteProprietaire $contexte): AccueilProprietaireResource
    {
        return new AccueilProprietaireResource($contexte);
    }
}
