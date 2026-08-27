<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Imagerie\EnregistrerExamenRequest;
use App\Http\Requests\V1\Imagerie\ModifierExamenRequest;
use App\Http\Resources\V1\ExamenImagerieResource;
use App\Models\Clinique;
use App\Models\ExamenImagerie;
use App\Services\ProjectionPlanningImagerie;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Worklist d'imagerie de la clinique du jeton (docs/data-model.md
 * §10). L'examen est le dossier maître : quand un RDV `imagerie` du
 * planning lui est lié, il pousse horaires et patient vers lui
 * (ProjectionPlanningImagerie). Jamais de suppression — un examen au
 * compte rendu signé ne se modifie plus.
 */
class ExamenImagerieController extends Controller
{
    public function __construct(private ProjectionPlanningImagerie $projection) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ExamenImagerie::class);

        $examens = ExamenImagerie::query()
            ->when($request->string('modalite')->isNotEmpty(), fn ($requete) => $requete->where('modalite', $request->string('modalite')))
            // Filtre compte rendu de l'écran : todo (à interpréter) | fait (signé).
            ->when($request->string('statut')->value() === 'todo', fn ($requete) => $requete->whereNull('signe_le'))
            ->when($request->string('statut')->value() === 'fait', fn ($requete) => $requete->whereNotNull('signe_le'))
            ->when($request->integer('patient_id') !== 0, fn ($requete) => $requete->where('patient_id', $request->integer('patient_id')))
            ->when($request->date('date'), function ($requete, $date): void {
                $fuseau = Clinique::find(app(ContexteClinique::class)->cliniqueId())?->fuseau_horaire ?? 'Europe/Zurich';
                $jour = CarbonImmutable::parse($date->toDateString(), $fuseau);
                $requete->whereBetween('realise_le', [$jour->utc(), $jour->addDay()->utc()]);
            })
            ->when($request->string('q')->isNotEmpty(), function ($requete) use ($request): void {
                $q = '%'.$request->string('q')->value().'%';
                $requete->where(fn (Builder $clause) => $clause
                    ->where('libelle', 'like', $q)
                    ->orWhereHas('patient', fn (Builder $patient) => $patient->where('nom', 'like', $q)));
            })
            ->with($this->relations())
            ->orderByDesc('realise_le')
            ->paginate($this->parPage($request));

        return ExamenImagerieResource::collection($examens);
    }

    public function store(EnregistrerExamenRequest $request): ExamenImagerieResource
    {
        $this->authorize('create', ExamenImagerie::class);

        $donnees = $request->validated();

        $examen = DB::transaction(function () use ($request, $donnees): ExamenImagerie {
            $examen = ExamenImagerie::create([
                ...$donnees,
                'prescripteur_id' => $donnees['prescripteur_id'] ?? app(ContexteClinique::class)->employe()?->id,
                'cree_par_id' => $request->user()?->id,
            ]);

            $this->projection->aligner($examen, $request->user());

            return $examen;
        });

        return new ExamenImagerieResource($examen->load($this->relations()));
    }

    public function show(ExamenImagerie $examen): ExamenImagerieResource
    {
        $this->authorize('view', $examen);

        return new ExamenImagerieResource($examen->load($this->relations()));
    }

    public function update(ModifierExamenRequest $request, ExamenImagerie $examen): ExamenImagerieResource
    {
        $this->authorize('update', $examen);

        if ($examen->estSigne()) {
            throw ValidationException::withMessages([
                'signe_le' => 'Un examen au compte rendu signé ne se modifie plus.',
            ]);
        }

        DB::transaction(function () use ($request, $examen): void {
            $examen->update($request->validated());

            $this->projection->aligner($examen, $request->user());
        });

        return new ExamenImagerieResource($examen->load($this->relations()));
    }

    /** @return array<int|string, mixed> */
    public static function relations(): array
    {
        return [
            'patient.client',
            'prescripteur' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'signePar' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'cliches',
        ];
    }
}
