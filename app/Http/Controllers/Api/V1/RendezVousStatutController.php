<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\RendezVous\AnnulerRendezVousRequest;
use App\Http\Requests\V1\RendezVous\ChangerStatutRequest;
use App\Http\Resources\V1\RendezVousResource;
use App\Models\RendezVous;
use App\Services\MutationRendezVous;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie d'un rendez-vous. `annule` est terminal — jamais de
 * suppression, jamais de retour en arrière ; le passage en `salle`
 * mémorise l'heure d'arrivée ; le no-show ne part que de `prevu` ou
 * `salle`, hors tampon. Chaque geste est audité en un lot.
 */
class RendezVousStatutController extends Controller
{
    public function __construct(private MutationRendezVous $mutation) {}

    public function changer(ChangerStatutRequest $request, RendezVous $rendezVous): RendezVousResource
    {
        $this->authorize('update', $rendezVous);

        if ($rendezVous->status === StatutRendezVous::Annule) {
            throw ValidationException::withMessages(['status' => 'Un rendez-vous annulé ne change plus de statut.']);
        }

        $statut = StatutRendezVous::from($request->validated('status'));

        $attributs = ['status' => $statut];

        if ($statut === StatutRendezVous::Salle && $rendezVous->arrived_at === null) {
            $attributs['arrived_at'] = now();
        }

        $this->mutation->appliquer($rendezVous, $attributs, $request->user());

        return new RendezVousResource($rendezVous);
    }

    public function nonVenu(Request $request, RendezVous $rendezVous): RendezVousResource
    {
        $this->authorize('update', $rendezVous);

        if ($rendezVous->estTampon()
            || ! in_array($rendezVous->status, [StatutRendezVous::Prevu, StatutRendezVous::Salle], true)) {
            throw ValidationException::withMessages([
                'status' => 'Le non-venu ne se marque que sur un rendez-vous prévu ou arrivé, hors tampon.',
            ]);
        }

        $this->mutation->appliquer($rendezVous, ['status' => StatutRendezVous::Noshow], $request->user());

        return new RendezVousResource($rendezVous);
    }

    public function annuler(AnnulerRendezVousRequest $request, RendezVous $rendezVous): RendezVousResource
    {
        $this->authorize('update', $rendezVous);

        if ($rendezVous->status === StatutRendezVous::Annule) {
            throw ValidationException::withMessages(['status' => 'Ce rendez-vous est déjà annulé.']);
        }

        $motif = trim((string) $request->validated('motif', ''));

        $this->mutation->appliquer(
            $rendezVous,
            ['status' => StatutRendezVous::Annule],
            $request->user(),
            entreesSupplementaires: $motif === '' ? [] : ['motifAnnulation' => [null, $motif]],
        );

        return new RendezVousResource($rendezVous);
    }
}
