<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\RendezVous\ProchainCreneauRequest;
use App\Http\Resources\V1\RendezVousResource;
use App\Models\RendezVous;
use App\Services\CalculateurCreneaux;
use App\Services\MutationRendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Création rapide au prochain créneau libre du jour — les lignes sous
 * absence bloquante sont déjà écartées par le calculateur.
 */
class ProchainCreneauController extends Controller
{
    public function __invoke(
        ProchainCreneauRequest $request,
        CalculateurCreneaux $calculateur,
        MutationRendezVous $mutation,
    ): RendezVousResource {
        $this->authorize('create', RendezVous::class);

        $donnees = $request->validated();
        $jour = isset($donnees['date'])
            ? CarbonImmutable::parse($donnees['date'])->startOfDay()
            : $calculateur->aujourdHui();
        $duree = (int) ($donnees['duree_minutes'] ?? 20);

        $creneau = $calculateur->prochainCreneau($jour, $duree);

        if ($creneau === null) {
            throw ValidationException::withMessages(['date' => 'Aucun créneau libre ce jour-là.']);
        }

        $attributs = $mutation->preparerCreation([
            ...$donnees,
            'ligne_planning_id' => $creneau['ligne_planning_id'],
            'starts_at' => $creneau['debut'],
            'duree_minutes' => $duree,
        ]);

        return new RendezVousResource($mutation->creer($attributs, $request->user()));
    }
}
