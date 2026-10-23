<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutSuggestionDiagnostique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Diagnostic\DemanderSuggestionRequest;
use App\Http\Resources\V1\SuggestionDiagnostiqueResource;
use App\Jobs\SuggererDiagnostic;
use App\Models\CompteRendu;
use App\Models\Organisation;
use App\Models\RendezVous;
use App\Models\SuggestionDiagnostique;
use App\Services\Diagnostic\ConsommationDiagnostic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Suggestion diagnostique d'un rendez-vous (23.10.2026) : le vétérinaire
 * la demande depuis le compte rendu, tel qu'il est à l'écran ; Claude
 * répond en arrière-plan (la fenêtre attend en relisant la dernière).
 * Payante à la suggestion rendue, sous le plafond de l'organisation.
 * Du dossier médical : domaine patients et niveau organisation, comme le
 * compte rendu.
 */
class SuggestionDiagnostiqueController extends Controller
{
    public function __construct(private ConsommationDiagnostic $consommation) {}

    /** Disponible ou non (et pourquoi), et le prix d'une suggestion. */
    public function etat(): JsonResponse
    {
        $this->authorize('viewAny', CompteRendu::class);

        return response()->json(['data' => Arr::only($this->consommation->etat($this->organisation()), ['disponible', 'motif', 'tarif'])]);
    }

    /** La dernière suggestion du rendez-vous. */
    public function afficher(string $rendezVous): SuggestionDiagnostiqueResource
    {
        $this->authorize('viewAny', CompteRendu::class);

        return new SuggestionDiagnostiqueResource(
            $this->derniere($this->rendezVousDeLOrganisation($rendezVous)) ?? abort(404),
        );
    }

    /**
     * Demande une suggestion. Une demande déjà en cours pour ce rendez-vous
     * est rendue telle quelle (un double clic ne paie pas deux fois).
     */
    public function demander(DemanderSuggestionRequest $request, string $rendezVous): JsonResponse
    {
        $this->authorize('create', CompteRendu::class);

        $rendezVous = $this->rendezVousDeLOrganisation($rendezVous);
        $derniere = $this->derniere($rendezVous);
        if ($derniere?->statut === StatutSuggestionDiagnostique::EnCours) {
            return (new SuggestionDiagnostiqueResource($derniere))->response();
        }

        $soap = $request->soap();
        $avecTranscription = SuggererDiagnostic::segments($rendezVous) !== [];
        if ($soap['s'] === '' && $soap['o'] === '' && $soap['a'] === '' && ! $avecTranscription) {
            throw ValidationException::withMessages(['compte_rendu' => 'Écrivez d’abord le motif ou l’examen clinique : la suggestion part du compte rendu.']);
        }

        $refus = $this->consommation->refus($this->organisation());
        if ($refus) {
            throw ValidationException::withMessages(['diagnostic' => $refus]);
        }

        $suggestion = SuggestionDiagnostique::query()->create([
            'clinique_id' => $rendezVous->clinique_id,
            'rendez_vous_id' => $rendezVous->id,
            'user_id' => $request->user()->id,
            'soap' => $soap,
            'avec_transcription' => $avecTranscription,
        ]);
        SuggererDiagnostic::dispatch($suggestion->id)->afterCommit();

        return (new SuggestionDiagnostiqueResource($suggestion->refresh()->load('user')))->response()->setStatusCode(201);
    }

    private function derniere(RendezVous $rendezVous): ?SuggestionDiagnostique
    {
        return SuggestionDiagnostique::query()->where('rendez_vous_id', $rendezVous->id)->latest('id')->with('user')->first();
    }

    private function organisation(): Organisation
    {
        return Organisation::query()->findOrFail(app(ContexteClinique::class)->organisationId());
    }

    /**
     * Le scope clinique du rendez-vous est levé mais la requête reste
     * bornée à l'organisation du jeton : hors organisation, 404.
     */
    private function rendezVousDeLOrganisation(string $id): RendezVous
    {
        return RendezVous::withoutGlobalScope('clinique')
            ->whereHas('clinique.societe', fn (Builder $societe) => $societe
                ->where('organisation_id', app(ContexteClinique::class)->organisationId()))
            ->findOrFail($id);
    }
}
