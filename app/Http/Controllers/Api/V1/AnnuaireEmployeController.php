<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AnnuaireEmployeResource;
use App\Models\Employe;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Annuaire de la clinique du jeton pour la grille de planning :
 * identité, ligne et poste de chaque employé — le minimum pour
 * dessiner les zones d'absence et choisir un praticien. Domaine
 * `planning` : une ASV sans le domaine `equipe` ne doit pas caser un
 * rendez-vous sur un praticien en congé. Aucune donnée RH (contrat,
 * salaire, congés, licenciement) : elles restent à /equipe/employes.
 */
class AnnuaireEmployeController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        $this->authorize('viewAnnuaire', Employe::class);

        $employes = Employe::query()
            ->with(['cliniques', 'lignePlanning'])
            ->orderBy('id')
            ->get();

        return AnnuaireEmployeResource::collection($employes);
    }
}
