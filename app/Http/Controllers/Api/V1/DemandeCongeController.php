<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\DemanderCongeRequest;
use App\Http\Resources\V1\AbsenceResource;
use App\Models\Absence;
use App\Models\Employe;
use App\Models\Organisation;
use App\Models\RendezVous;
use App\Services\Equipe\SoldesConges;
use App\Services\MutationRendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Chacun demande ses congés depuis le planning (Jim, 11.11.2026, É10).
 * La demande attend la validation, sauf si l'organisation permet de
 * poser ses congés sans validation (désactivé de base) ; même alors,
 * des rendez-vous pris ces jours-là la laissent en attente : l'employé
 * n'annule jamais lui-même les rendez-vous des clients.
 */
class DemandeCongeController extends Controller
{
    public function __construct(private MutationRendezVous $mutation) {}

    public function store(DemanderCongeRequest $request): JsonResponse
    {
        $moi = $this->moi();
        $donnees = $request->validated();

        $chevauche = Absence::query()
            ->where('employe_id', $moi->id)
            ->whereIn('statut', [StatutAbsence::Validee, StatutAbsence::EnAttente])
            ->whereDate('debut_le', '<=', $donnees['fin_le'])
            ->whereDate('fin_le', '>=', $donnees['debut_le'])
            ->exists();
        if ($chevauche) {
            throw ValidationException::withMessages(['debut_le' => 'Ces jours touchent une absence déjà posée ou demandée.']);
        }
        // Un week-end ou des jours fériés seulement : aucun jour de congé à demander
        if (SoldesConges::joursOuvres(CarbonImmutable::parse($donnees['debut_le']), CarbonImmutable::parse($donnees['fin_le']), SoldesConges::cliniqueDe($moi)) == 0) {
            throw ValidationException::withMessages(['debut_le' => 'Ces jours ne sont pas travaillés : aucun jour de congé à demander.']);
        }

        $absence = new Absence([
            'employe_id' => $moi->id,
            'type' => TypeAbsence::Conge,
            'debut_le' => $donnees['debut_le'],
            'fin_le' => $donnees['fin_le'],
            'note' => $donnees['note'] ?? null,
        ]);
        $rendezVous = $this->mutation->rendezVousPendant($absence)->reject(fn (RendezVous $rdv): bool => $rdv->estTampon())->count();
        $sansValidation = $this->sansValidation() && $rendezVous === 0;
        $absence->statut = $sansValidation ? StatutAbsence::Validee : StatutAbsence::EnAttente;
        $absence->valide_par_user_id = $sansValidation ? $request->user()->id : null;
        $absence->save();

        return (new AbsenceResource($absence))
            ->additional(['meta' => ['rendez_vous' => $rendezVous]])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Sa demande en attente se retire ; un congé validé, seulement celui
     * qu'on a posé soi-même sans validation et pas encore commencé —
     * jamais celui qu'un autre a validé (audit C04, 14.11.2026).
     */
    public function destroy(Request $request, Absence $absence): Response
    {
        $moi = $this->moi();
        $peutRetirer = $absence->employe_id === $moi->id
            && $absence->type === TypeAbsence::Conge
            && ($absence->statut === StatutAbsence::EnAttente
                || ($absence->statut === StatutAbsence::Validee && $this->sansValidation()
                    && $absence->valide_par_user_id === $request->user()->id && $absence->debut_le->gt(aujourdhui())));
        if (! $peutRetirer) {
            throw new AuthorizationException('Seule sa propre demande se retire.');
        }

        $absence->delete();

        return response()->noContent();
    }

    private function moi(): Employe
    {
        return app(ContexteClinique::class)->employe() ?? throw new AuthorizationException;
    }

    private function sansValidation(): bool
    {
        return (bool) Organisation::query()->whereKey(app(ContexteClinique::class)->organisationId())->value('conges_sans_validation');
    }
}
