<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutAbsence;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Absences\EnregistrerAbsenceRequest;
use App\Http\Requests\V1\Absences\ModifierAbsenceRequest;
use App\Http\Resources\V1\AbsenceResource;
use App\Models\Absence;
use App\Services\MutationRendezVous;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Cycle : demande `att` → validation (la colonne du praticien est vidée
 * sur la période : rendez-vous annulés et audités avec motif) ou refus.
 * La saisie directe en `ok` vide la colonne pareillement. La
 * suppression est tolérée (rouvre la colonne) — les rendez-vous annulés
 * ne sont pas restaurés.
 */
class AbsenceController extends Controller
{
    public function __construct(private MutationRendezVous $mutation) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Absence::class);

        $absences = Absence::query()
            ->when($request->integer('employe_id'), fn ($requete, $employe) => $requete->where('employe_id', $employe))
            ->when($request->string('statut')->isNotEmpty(), fn ($requete) => $requete->where('statut', $request->string('statut')))
            ->orderByDesc('debut_le')
            ->orderByDesc('id')
            ->paginate($this->parPage($request));

        return AbsenceResource::collection($absences);
    }

    public function store(EnregistrerAbsenceRequest $request): AbsenceResource
    {
        $this->authorize('create', Absence::class);

        $donnees = $request->validated();
        $statut = StatutAbsence::from($donnees['statut'] ?? StatutAbsence::EnAttente->value);

        $absence = Absence::create([
            ...$donnees,
            'statut' => $statut,
            'valide_par_user_id' => $statut === StatutAbsence::Validee ? $request->user()->id : null,
        ]);

        $resultat = $this->mutation->annulerPourAbsence($absence, $request->user());

        return (new AbsenceResource($absence))
            ->additional(['meta' => ['rendez_vous_annules' => $resultat['annules'], 'rendez_vous_restants' => $resultat['restants']]]);
    }

    public function show(Absence $absence): AbsenceResource
    {
        $this->authorize('view', $absence);

        return new AbsenceResource($absence);
    }

    public function update(ModifierAbsenceRequest $request, Absence $absence): AbsenceResource
    {
        $this->authorize('update', $absence);

        $absence->update($request->validated());

        // Une absence validée prolongée ou avancée vide aussi les nouveaux
        // jours (24.10.2026) : les rendez-vous déjà annulés ne bougent plus
        $resultat = $this->mutation->annulerPourAbsence($absence, $request->user());

        return (new AbsenceResource($absence))
            ->additional(['meta' => ['rendez_vous_annules' => $resultat['annules'], 'rendez_vous_restants' => $resultat['restants']]]);
    }

    public function destroy(Absence $absence): Response
    {
        $this->authorize('delete', $absence);

        $absence->delete();

        return response()->noContent();
    }

    public function valider(Request $request, Absence $absence): AbsenceResource
    {
        $this->authorize('valider', $absence);

        if ($absence->statut !== StatutAbsence::EnAttente) {
            throw ValidationException::withMessages(['statut' => 'Seule une demande en attente se valide.']);
        }

        $absence->update([
            'statut' => StatutAbsence::Validee,
            'valide_par_user_id' => $request->user()->id,
        ]);

        $resultat = $this->mutation->annulerPourAbsence($absence, $request->user());

        return (new AbsenceResource($absence))
            ->additional(['meta' => ['rendez_vous_annules' => $resultat['annules'], 'rendez_vous_restants' => $resultat['restants']]]);
    }

    public function refuser(Request $request, Absence $absence): AbsenceResource
    {
        $this->authorize('refuser', $absence);

        if ($absence->statut !== StatutAbsence::EnAttente) {
            throw ValidationException::withMessages(['statut' => 'Seule une demande en attente se refuse.']);
        }

        $absence->update([
            'statut' => StatutAbsence::Refusee,
            'valide_par_user_id' => $request->user()->id,
        ]);

        return new AbsenceResource($absence);
    }
}
