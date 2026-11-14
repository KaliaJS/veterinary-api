<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\StatutAbsence;
use App\Enums\TypeAbsence;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Equipe\PeriodeEquipeRequest;
use App\Models\Absence;
use App\Models\Employe;
use App\Models\Organisation;
use App\Services\Equipe\SoldesConges;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Le planning de l'équipe, lu par chacun (Jim, 11.11.2026, É10 : « Team
 * planning, read-only ») : sans le droit « Gérer les absences » (audit
 * C29, 14.11.2026 : le droit Équipe, donné à tous, ne protégeait
 * personne), les autres ne sont qu'absents, sans la raison ni la note
 * (une maladie est une donnée de santé), et leurs demandes en attente ne
 * se montrent pas ; ses propres absences se lisent en entier. Les gardes ont leur propre ligne
 * (`/equipe/gardes`) : une absence « garde » n'y figure pas.
 */
class PlanningEquipeController extends Controller
{
    public function __invoke(PeriodeEquipeRequest $request): JsonResponse
    {
        $contexte = app(ContexteClinique::class);
        $moi = $contexte->employe();
        $peutGerer = Gate::allows(DomainePermission::GererAbsences->value);

        $absences = Absence::query()
            ->whereIn('statut', [StatutAbsence::Validee, StatutAbsence::EnAttente])
            ->where('type', '!=', TypeAbsence::Garde)
            ->whereDate('debut_le', '<=', $request->validated('au'))
            ->whereDate('fin_le', '>=', $request->validated('du'))
            ->when(! $peutGerer, fn ($requete) => $requete->where(fn ($visibles) => $visibles
                ->where('statut', StatutAbsence::Validee)
                ->orWhere('employe_id', $moi?->id)))
            ->orderBy('debut_le')
            ->orderBy('id')
            ->get()
            ->map(function (Absence $absence) use ($peutGerer, $moi): array {
                $voitTout = $peutGerer || $absence->employe_id === $moi?->id;

                return [
                    'id' => $absence->id,
                    'employe_id' => $absence->employe_id,
                    'type' => $voitTout ? $absence->type->value : null,
                    'debut_le' => $absence->debut_le->toDateString(),
                    'fin_le' => $absence->fin_le->toDateString(),
                    'statut' => $absence->statut->value,
                    'note' => $voitTout ? $absence->note : null,
                    // Qui l'a validée : un congé posé par soi-même sans validation se retire, pas celui qu'un autre a validé
                    'valide_par_user_id' => $voitTout ? $absence->valide_par_user_id : null,
                ];
            })
            ->values();

        $employes = Employe::query()
            ->where('acces_suspendu', false)
            ->orderBy('nom')
            ->get()
            ->map(fn (Employe $employe): array => [
                'id' => $employe->id,
                'nom' => $employe->nom,
                'initiales' => $employe->initiales,
                'role_libelle' => $employe->role_libelle,
                'photo_url' => $employe->photoUrl(),
            ])
            ->values();

        // Les jours fériés de la clinique sur la période (É23)
        $clinique = $contexte->clinique();
        $du = CarbonImmutable::parse($request->validated('du'));
        $au = CarbonImmutable::parse($request->validated('au'));
        $feries = [];
        for ($annee = $du->year; $annee <= $au->year; $annee++) {
            foreach ($clinique?->joursFeries($annee) ?? [] as $jour => $ferie) {
                if ($jour >= $du->toDateString() && $jour <= $au->toDateString()) {
                    $feries[] = ['jour' => $jour, 'nom' => $ferie['nom'], 'part' => SoldesConges::nombre($ferie['part'])];
                }
            }
        }

        return response()->json(['data' => [
            'employes' => $employes,
            'absences' => $absences,
            'feries' => $feries,
            'peut_gerer' => $peutGerer,
            'moi' => $moi === null ? null : [
                'employe_id' => $moi->id,
                'conges' => $moi->load('congesValides')->soldeConges(),
                'sans_validation' => (bool) Organisation::query()->whereKey($contexte->organisationId())->value('conges_sans_validation'),
            ],
        ]]);
    }
}
