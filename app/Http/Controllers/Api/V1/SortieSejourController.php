<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DomainePermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FactureResource;
use App\Http\Resources\V1\SejourResource;
use App\Models\Sejour;
use App\Services\FactureSejour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sortie d'hospitalisation : le séjour est clos (box libéré, jamais
 * supprimé) et sa facture naît en caisse — effet système, même sans le
 * domaine facturation ; la réponse ne l'expose qu'à qui peut la voir.
 * L'aperçu sert la modale de sortie sans rien créer.
 */
class SortieSejourController extends Controller
{
    public function __construct(private FactureSejour $factureSejour) {}

    /**
     * Aperçu dérivé de la facture du séjour — rien n'est créé. Les
     * montants ne vont qu'à qui a la facturation (23.10.2026) ; les
     * autres voient les jours et les prestations.
     */
    public function apercu(Sejour $sejour): JsonResponse
    {
        $this->authorize('view', $sejour);

        $lignes = $this->factureSejour->lignes($sejour);
        if (! Gate::allows(DomainePermission::Facturation->value)) {
            return response()->json(['data' => [
                'jours' => $sejour->joursFactures(),
                'lignes' => array_map(fn (array $ligne): array => ['libelle' => $ligne['libelle'], 'quantite' => $ligne['quantite'], 'type' => $ligne['type']], $lignes),
                'total_chf' => null,
            ]]);
        }

        return response()->json(['data' => [
            'jours' => $sejour->joursFactures(),
            // Le total de chaque ligne servi : l'écran ne recalcule rien (24.10.2026)
            'lignes' => array_map(fn (array $ligne): array => [...$ligne, 'total_chf' => round($ligne['quantite'] * $ligne['prix_chf'], 2)], $lignes),
            'total_chf' => round(array_sum(array_map(
                fn (array $ligne): float => $ligne['quantite'] * $ligne['prix_chf'],
                $lignes,
            )), 2),
        ]]);
    }

    public function sortir(Request $request, Sejour $sejour): SejourResource
    {
        $this->authorize('update', $sejour);

        if ($sejour->estSorti()) {
            throw ValidationException::withMessages(['sejour' => 'Ce séjour est déjà sorti.']);
        }

        $facture = $this->factureSejour->sortir($sejour, $request->user());

        $resource = new SejourResource($sejour->load([
            'patient.client',
            'veterinaire' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'soins.produit',
            'constantes',
        ]));

        if (Gate::allows('facturation')) {
            $resource->additional(['facture' => new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']))]);
        }

        return $resource;
    }
}
