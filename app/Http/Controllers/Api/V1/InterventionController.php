<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Bloc\EnregistrerInterventionRequest;
use App\Http\Requests\V1\Bloc\ModifierInterventionRequest;
use App\Http\Resources\V1\InterventionResource;
use App\Models\Clinique;
use App\Models\Intervention;
use App\Models\Salle;
use App\Services\ProjectionPlanningBloc;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Interventions du bloc de la clinique du jeton (docs/data-model.md
 * §11). L'intervention est le dossier maître : quand un RDV `chir` du
 * planning lui est lié, elle pousse horaires, salle et patient vers lui
 * (ProjectionPlanningBloc). Jamais de suppression — le cycle se clôt en
 * `fini` ; une intervention terminée n'accepte plus que la note
 * opératoire.
 */
class InterventionController extends Controller
{
    public function __construct(private ProjectionPlanningBloc $projection) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Intervention::class);

        $interventions = Intervention::query()
            ->when($request->date('date'), function ($requete, $date): void {
                $fuseau = Clinique::find(app(ContexteClinique::class)->cliniqueId())->fuseau_horaire ?? 'Europe/Zurich';
                $jour = CarbonImmutable::parse($date->toDateString(), $fuseau);
                $requete->whereBetween('prevu_le', [$jour->utc(), $jour->addDay()->utc()]);
            })
            ->when($request->string('statut')->isNotEmpty(), fn ($requete) => $requete->where('statut', $request->string('statut')))
            ->with($this->relations())
            ->orderBy('prevu_le')
            ->paginate($this->parPage($request));

        return InterventionResource::collection($interventions);
    }

    public function store(EnregistrerInterventionRequest $request): InterventionResource
    {
        $this->authorize('create', Intervention::class);

        $donnees = $request->validated();

        $intervention = DB::transaction(function () use ($request, $donnees): Intervention {
            $intervention = Intervention::create([
                ...$donnees,
                'salle_nom' => Salle::findOrFail($donnees['salle_id'])->nom,
                'protocole_anesthesique' => $donnees['protocole_anesthesique'] ?? Intervention::PROTOCOLE_PAR_DEFAUT,
                'cree_par_id' => $request->user()?->id,
            ]);

            $intervention->creerChecklistDeBase();

            $this->projection->aligner($intervention, $request->user());

            return $intervention;
        });

        return new InterventionResource($intervention->load($this->relations()));
    }

    public function show(Intervention $intervention): InterventionResource
    {
        $this->authorize('view', $intervention);

        return new InterventionResource($intervention->load($this->relations()));
    }

    public function update(ModifierInterventionRequest $request, Intervention $intervention): InterventionResource
    {
        $this->authorize('update', $intervention);

        $donnees = $request->validated();

        if ($intervention->estFinie() && array_keys($donnees) !== ['note_operatoire']) {
            throw ValidationException::withMessages([
                'statut' => 'Une intervention terminée n\'accepte plus que la note opératoire.',
            ]);
        }

        DB::transaction(function () use ($request, $intervention, $donnees): void {
            if (array_key_exists('salle_id', $donnees)) {
                $donnees['salle_nom'] = Salle::findOrFail($donnees['salle_id'])->nom;
            }

            $intervention->update($donnees);

            $this->projection->aligner($intervention, $request->user());
        });

        return new InterventionResource($intervention->load($this->relations()));
    }

    /** @return array<int|string, mixed> */
    private function relations(): array
    {
        return [
            'patient.client',
            'chirurgien' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'checklist',
            'transitions',
        ];
    }
}
