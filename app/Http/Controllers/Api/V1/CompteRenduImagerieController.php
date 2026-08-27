<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Imagerie\SignerCompteRenduRequest;
use App\Http\Resources\V1\ExamenImagerieResource;
use App\Http\Resources\V1\FactureResource;
use App\Models\ExamenImagerie;
use App\Models\Facture;
use App\Services\CreationFacture;
use App\Services\ProjectionPlanningImagerie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Signature du compte rendu (sign du front) : écrit constatations et
 * conclusion, signe (l'employé du jeton) et fige l'examen — une seule
 * signature. Sur un RDV `imagerie` lié, elle clôt la projection
 * planning : RDV `fini`, et facture de l'acte d'imagerie comme tout
 * RDV fini (effet système, débrayable par `sans_facture`, exposé selon
 * le Gate facturation).
 */
class CompteRenduImagerieController extends Controller
{
    public function __construct(
        private ProjectionPlanningImagerie $projection,
        private CreationFacture $creationFacture,
    ) {}

    public function __invoke(SignerCompteRenduRequest $request, ExamenImagerie $examen): ExamenImagerieResource
    {
        $this->authorize('update', $examen);

        if ($examen->estSigne()) {
            throw ValidationException::withMessages(['signe_le' => 'Le compte rendu est déjà signé.']);
        }

        $facture = DB::transaction(function () use ($request, $examen): ?Facture {
            $examen->update([
                'constatations' => $request->validated('constatations'),
                'conclusion' => $request->validated('conclusion') ?? '',
                'signe_par_id' => app(ContexteClinique::class)->employe()?->id,
                'signe_le' => now(),
            ]);

            $this->projection->refleterStatut($examen, StatutRendezVous::Fini, $request->user());

            $rendezVous = $examen->rendezVous;

            if ($rendezVous === null
                || $rendezVous->status !== StatutRendezVous::Fini
                || $request->boolean('sans_facture')) {
                return null;
            }

            return $this->creationFacture->depuisRendezVous($rendezVous, $request->user());
        });

        $resource = new ExamenImagerieResource($examen->load(ExamenImagerieController::relations()));

        if ($facture !== null && Gate::allows('facturation')) {
            $resource->additional(['facture' => new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']))]);
        }

        return $resource;
    }
}
