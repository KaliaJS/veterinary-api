<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\CashStatisticsRequest;
use App\Models\Clinique;
use App\Models\Facture;
use App\Services\Facturation\CashStatistics;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Statistiques de la Caisse sur une période choisie (demande de Jim,
 * 06.11.2026 : l'établi, ses courbes, la période à la poignée) : les
 * indicateurs, les séries par créneau, toute l'histoire pour l'aperçu, les
 * ventilations et leurs évolutions, servis tels quels à l'écran.
 *
 * Les factures se lisent pour toute l'organisation avec le droit de la
 * caisse (elles suivent le patient) : toute l'organisation par défaut, ou
 * une de ses cliniques. Les jours se comptent dans le fuseau de la clinique
 * choisie, sinon celui de la clinique du jeton.
 */
class CashStatisticsController extends Controller
{
    public function __invoke(CashStatisticsRequest $request, CashStatistics $statistics): JsonResponse
    {
        $this->authorize('viewAny', Facture::class);

        $contexte = app(ContexteClinique::class);
        $organisationClinics = $contexte->clinique()->societe->organisation->cliniques()->orderBy('cliniques.id')->get();
        $clinicId = $request->filled('clinic_id') ? $request->integer('clinic_id') : null;
        $clinics = $clinicId === null ? $organisationClinics : $organisationClinics->where('id', $clinicId)->values();
        $timezone = ($clinicId === null ? $contexte->clinique() : $clinics->first())?->fuseau_horaire ?: 'Europe/Zurich';

        return response()->json(['data' => $statistics->compute(
            $clinics,
            $organisationClinics,
            $clinicId,
            $timezone,
            CarbonImmutable::parse($request->string('from')->toString()),
            CarbonImmutable::parse($request->string('to')->toString()),
            $request->input('compare'),
            $request->boolean('history', true),
        )]);
    }
}
