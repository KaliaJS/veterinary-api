<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DrapeauRendezVous;
use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\FilesAttente\EnregistrerListeAttenteRequest;
use App\Http\Requests\V1\FilesAttente\PlacerDepuisFileRequest;
use App\Http\Resources\V1\ListeAttenteResource;
use App\Http\Resources\V1\RendezVousResource;
use App\Models\ListeAttente;
use App\Models\Patient;
use App\Services\CalculateurCreneaux;
use App\Services\MutationRendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Liste d'attente — patients à rappeler dès qu'un créneau se libère.
 * L'offre de créneau libéré (annulation, no-show) est un état d'UI ;
 * son acceptation passe par « placer », qui borne au besoin la durée au
 * créneau offert via `duree_minutes`.
 */
class ListeAttenteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ListeAttente::class);

        return ListeAttenteResource::collection(
            ListeAttente::query()
                ->orderBy('inscrit_le')
                ->orderBy('id')
                ->paginate($this->parPage($request)),
        );
    }

    public function store(EnregistrerListeAttenteRequest $request): ListeAttenteResource
    {
        $this->authorize('create', ListeAttente::class);

        $donnees = $request->validated();

        if (($donnees['patient_id'] ?? null) !== null) {
            $donnees = [...$donnees, ...Patient::findOrFail($donnees['patient_id'])->denormalisation()];
        }

        return new ListeAttenteResource(ListeAttente::create([
            ...$donnees,
            'inscrit_le' => now(),
        ]));
    }

    public function placer(
        PlacerDepuisFileRequest $request,
        ListeAttente $inscription,
        CalculateurCreneaux $calculateur,
        MutationRendezVous $mutation,
    ): RendezVousResource {
        $this->authorize('placer', $inscription);

        $donnees = $request->validated();
        $duree = min((int) ($donnees['duree_minutes'] ?? $inscription->duree_minutes), $inscription->duree_minutes);

        if (isset($donnees['starts_at'])) {
            $ligneId = (int) $donnees['ligne_planning_id'];
            $debut = CarbonImmutable::parse($donnees['starts_at']);
        } else {
            $creneau = $calculateur->prochainCreneau($calculateur->aujourdHui(), $duree);

            if ($creneau === null) {
                throw ValidationException::withMessages(['starts_at' => 'Aucun créneau libre aujourd’hui.']);
            }

            [$ligneId, $debut] = [$creneau['ligne_planning_id'], $creneau['debut']];
        }

        $attributs = $mutation->preparerCreation([
            'ligne_planning_id' => $ligneId,
            'starts_at' => $debut,
            'duree_minutes' => $duree,
            'patient_id' => $inscription->patient_id,
            'patient_name' => $inscription->patient_name,
            'species' => $inscription->species,
            'owner_name' => $inscription->owner_name,
            'type' => TypeRendezVous::Consultation,
            'status' => StatutRendezVous::Prevu,
            'flags' => [DrapeauRendezVous::NouveauClient],
            'reason' => $inscription->reason,
        ]);

        $rendezVous = DB::transaction(function () use ($mutation, $attributs, $request, $inscription) {
            $rendezVous = $mutation->creer($attributs, $request->user());
            $inscription->delete();

            return $rendezVous;
        });

        return new RendezVousResource($rendezVous);
    }

    public function destroy(ListeAttente $inscription): Response
    {
        $this->authorize('delete', $inscription);

        $inscription->delete();

        return response()->noContent();
    }
}
