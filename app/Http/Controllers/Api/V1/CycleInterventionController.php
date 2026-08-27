<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutIntervention;
use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FactureResource;
use App\Http\Resources\V1\InterventionResource;
use App\Models\Facture;
use App\Models\Intervention;
use App\Services\CreationFacture;
use App\Services\ProjectionPlanningBloc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Cycle opératoire prevu → prep → cours → reveil → fini, un cran à la
 * fois, sans retour. L'incision (`cours`) est BLOQUÉE tant que la
 * checklist de sécurité n'est pas complète ; chaque passage laisse une
 * transition au journal. Sur un RDV `chir` lié : le démarrage passe le
 * RDV en `cours`, la clôture en `fini` — et facture alors l'acte de
 * chirurgie comme tout RDV fini (effet système, débrayable par
 * `sans_facture`, exposé selon le Gate facturation).
 */
class CycleInterventionController extends Controller
{
    public function __construct(
        private ProjectionPlanningBloc $projection,
        private CreationFacture $creationFacture,
    ) {}

    public function __invoke(Request $request, Intervention $intervention): InterventionResource
    {
        $this->authorize('update', $intervention);

        $suivant = $intervention->statut->suivant();

        if ($suivant === null) {
            throw ValidationException::withMessages(['statut' => 'Une intervention terminée ne change plus de statut.']);
        }

        if ($suivant === StatutIntervention::Cours && ! $intervention->checklistEstComplete()) {
            throw ValidationException::withMessages(['checklist' => 'Checklist de sécurité incomplète.']);
        }

        $facture = DB::transaction(function () use ($request, $intervention, $suivant): ?Facture {
            $intervention->update([
                'statut' => $suivant,
                ...$suivant === StatutIntervention::Cours ? ['incision_a' => now()] : [],
            ]);

            $intervention->transitions()->create([
                'statut' => $suivant,
                'user_id' => $request->user()?->id,
                'created_at' => now(),
            ]);

            if ($suivant === StatutIntervention::Cours) {
                $this->projection->refleterStatut($intervention, StatutRendezVous::Cours, $request->user());
            }

            if ($suivant !== StatutIntervention::Fini) {
                return null;
            }

            $this->projection->refleterStatut($intervention, StatutRendezVous::Fini, $request->user());

            $rendezVous = $intervention->rendezVous;

            if ($rendezVous === null
                || $rendezVous->status !== StatutRendezVous::Fini
                || $request->boolean('sans_facture')) {
                return null;
            }

            return $this->creationFacture->depuisRendezVous($rendezVous, $request->user());
        });

        $resource = new InterventionResource($intervention->load([
            'patient.client',
            'chirurgien' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'checklist',
            'transitions',
        ]));

        if ($facture !== null && Gate::allows('facturation')) {
            $resource->additional(['facture' => new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']))]);
        }

        return $resource;
    }
}
