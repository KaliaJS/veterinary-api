<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\PieceNumerotee;
use App\Enums\StatutOrdonnance;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrdonnanceResource;
use App\Models\Ordonnance;
use App\Services\Numeroteur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renouvellement et clôture (docs/data-model.md §12). Le renouvellement
 * crée une NOUVELLE ordonnance datée du jour avec un renouvellement de
 * moins et clôt la précédente — sans nouvelle sortie de stock, la
 * délivrance restant l'affaire de la boutique (parité front). La
 * clôture rend l'ordonnance non délivrable.
 */
class CycleOrdonnanceController extends Controller
{
    public function __construct(private Numeroteur $numeroteur) {}

    public function renouveler(Request $request, Ordonnance $ordonnance): OrdonnanceResource
    {
        $this->authorize('update', $ordonnance);

        if ($ordonnance->statut !== StatutOrdonnance::Active) {
            throw ValidationException::withMessages(['statut' => 'Seule une ordonnance en cours se renouvelle.']);
        }

        if ($ordonnance->renouvellements < 1) {
            throw ValidationException::withMessages(['renouvellements' => 'Plus aucun renouvellement restant.']);
        }

        $contexte = app(ContexteClinique::class);

        $renouvellement = DB::transaction(function () use ($ordonnance, $contexte): Ordonnance {
            $renouvellement = Ordonnance::create([
                'clinique_id' => $contexte->cliniqueId(),
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::Ordonnance),
                'patient_id' => $ordonnance->patient_id,
                'employe_id' => $contexte->employe()->id,
                'signee_le' => today(),
                'renouvellements' => $ordonnance->renouvellements - 1,
                'statut' => StatutOrdonnance::Active,
                'note' => $ordonnance->note,
                'ordonnance_precedente_id' => $ordonnance->id,
            ]);

            foreach ($ordonnance->lignes as $ligne) {
                $renouvellement->lignes()->create([
                    'produit_id' => $ligne->produit_id,
                    'posologie' => $ligne->posologie,
                    'duree' => $ligne->duree,
                ]);
            }

            $ordonnance->update(['statut' => StatutOrdonnance::Cloturee]);

            return $renouvellement;
        });

        return new OrdonnanceResource($renouvellement->load(['lignes.produit', 'employe', 'patient']));
    }

    public function cloturer(Request $request, Ordonnance $ordonnance): OrdonnanceResource
    {
        $this->authorize('update', $ordonnance);

        if ($ordonnance->statut !== StatutOrdonnance::Active) {
            throw ValidationException::withMessages(['statut' => 'Cette ordonnance est déjà clôturée.']);
        }

        $ordonnance->update(['statut' => StatutOrdonnance::Cloturee]);

        return new OrdonnanceResource($ordonnance->load([
            'lignes.produit',
            'employe' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
        ]));
    }
}
