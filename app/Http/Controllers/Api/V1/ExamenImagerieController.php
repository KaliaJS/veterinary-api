<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\ModaliteImagerie;
use App\Http\Controllers\Api\V1\Concerns\ServedListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Imagerie\EnregistrerExamenRequest;
use App\Http\Requests\V1\Imagerie\ModifierExamenRequest;
use App\Http\Resources\V1\ExamenImagerieResource;
use App\Models\Clinique;
use App\Models\ExamenImagerie;
use App\Models\Patient;
use App\Services\ProjectionPlanningImagerie;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
    use ServedListing;

    public function __construct(private ProjectionPlanningImagerie $projection) {}

    /** Les tris de l'écran Imagerie (07.11.2026) ; sans `tri`, du plus récent au plus ancien */
    public const array SORTS = ['todo', 'recent', 'old', 'patient'];

    /**
     * La worklist de la clinique, triée, filtrée (modalité, compte rendu
     * `todo` à interpréter ou `fait` signé) et cherchée par le serveur sur
     * tous les examens, page après page (tri au-delà de 100 lignes, 07.11.2026).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ExamenImagerie::class);
        $request->validate([
            ...$this->listingRules(self::SORTS),
            'modalite' => ['sometimes', 'nullable', Rule::enum(ModaliteImagerie::class)],
            'statut' => ['sometimes', 'nullable', 'string', Rule::in(['todo', 'fait'])],
        ]);
        $motif = $this->searchPattern($request);

        $examens = ExamenImagerie::query()
            ->when($request->string('modalite')->isNotEmpty(), fn ($requete) => $requete->where('modalite', $request->string('modalite')))
            // Filtre compte rendu de l'écran : todo (à interpréter) | fait (signé).
            ->when($request->string('statut')->value() === 'todo', fn ($requete) => $requete->whereNull('signe_le'))
            ->when($request->string('statut')->value() === 'fait', fn ($requete) => $requete->whereNotNull('signe_le'))
            ->when($request->integer('patient_id') !== 0, fn ($requete) => $requete->where('patient_id', $request->integer('patient_id')))
            ->when($request->date('date'), function ($requete, $date): void {
                $fuseau = Clinique::find(app(ContexteClinique::class)->cliniqueId())->fuseau_horaire ?? 'Europe/Zurich';
                $jour = CarbonImmutable::parse($date->toDateString(), $fuseau);
                $requete->whereBetween('realise_le', [$jour->utc(), $jour->addDay()->utc()]);
            })
            // L'examen, l'animal et son propriétaire
            ->when($motif !== null, fn (Builder $requete) => $requete->where(fn (Builder $clause) => $clause
                ->tap(fn (Builder $c) => $this->whereLikeAny($c, ['examens_imagerie.libelle'], $motif))
                ->orWhereHas('patient', fn (Builder $patient) => $patient->where(fn (Builder $p) => $p
                    ->tap(fn (Builder $pp) => $this->whereLikeAny($pp, ['patients.nom'], $motif))
                    ->orWhereHas('client', fn (Builder $client) => $this->whereLikeAny($client, ['clients.nom', "coalesce(clients.prenom, '')"], $motif))))))
            ->with($this->relations())
            ->tap(fn (Builder $requete) => $this->sortStudies($requete, $this->sortOf($request, 'recent')))
            ->paginate($this->parPage($request));

        return ExamenImagerieResource::collection($examens);
    }

    /**
     * « À interpréter d'abord » : les examens sans compte rendu signé, puis
     * les signés, chacun du plus récent au plus ancien ; la date ; le nom de
     * l'animal (puis le plus récent). Le numéro départage, toujours.
     *
     * @param  Builder<ExamenImagerie>  $requete
     */
    private function sortStudies(Builder $requete, string $tri): void
    {
        match ($tri) {
            'todo' => $requete->orderByRaw('examens_imagerie.signe_le is not null')->orderByDesc('realise_le')->orderByDesc('id'),
            'old' => $requete->orderBy('realise_le')->orderBy('id'),
            'patient' => $requete
                ->orderBy(Patient::withoutGlobalScopes()->selectRaw('lower(patients.nom)')->whereColumn('patients.id', 'examens_imagerie.patient_id')->limit(1))
                ->orderByDesc('realise_le')
                ->orderByDesc('id'),
            default => $requete->orderByDesc('realise_le')->orderByDesc('id'),
        };
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
            'confirmations.auteur',
            'confirmations.document',
        ];
    }
}
