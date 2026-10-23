<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\PieceNumerotee;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Laboratoire\DemanderAnalyseRequest;
use App\Http\Requests\V1\Laboratoire\ModifierAnalyseRequest;
use App\Http\Resources\V1\AnalyseResource;
use App\Models\Analyse;
use App\Models\PanelLabo;
use App\Services\Numeroteur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Demandes d'analyses du laboratoire de la clinique du jeton
 * (docs/data-model.md §9). La demande naît en `attente` avec ses
 * lignes de résultats vides — instantané des panels et bornes du
 * référentiel au moment de la demande. Jamais de suppression ; une
 * demande ne se réécrit pas, seule la note bouge, jusqu'à la validation.
 */
class AnalyseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Analyse::class);

        $analyses = Analyse::query()
            ->when($request->string('statut')->isNotEmpty(), fn ($requete) => $requete->where('statut', $request->string('statut')))
            ->when($request->string('priorite')->isNotEmpty(), fn ($requete) => $requete->where('priorite', $request->string('priorite')))
            ->when($request->integer('patient_id') !== 0, fn ($requete) => $requete->where('patient_id', $request->integer('patient_id')))
            ->when($request->string('q')->isNotEmpty(), function ($requete) use ($request): void {
                $q = '%'.$request->string('q')->value().'%';
                $requete->where(fn (Builder $clause) => $clause
                    ->where('numero', 'like', $q)
                    ->orWhereHas('patient', fn (Builder $patient) => $patient->where('nom', 'like', $q)));
            })
            ->with($this->relations())
            // Tri de l'écran Laboratoire : par étape du cycle, puis les
            // prélèvements les plus récents en tête.
            ->orderByRaw("case statut when 'attente' then 0 when 'cours' then 1 when 'valid' then 2 else 3 end")
            ->orderByDesc('preleve_le')
            ->paginate($this->parPage($request));

        return AnalyseResource::collection($analyses);
    }

    public function store(DemanderAnalyseRequest $request, Numeroteur $numeroteur): AnalyseResource
    {
        $this->authorize('create', Analyse::class);

        $donnees = $request->validated();

        $analyse = DB::transaction(function () use ($request, $numeroteur, $donnees): Analyse {
            $analyse = Analyse::create([
                'numero' => $numeroteur->attribuer(PieceNumerotee::AnalyseLabo),
                'patient_id' => $donnees['patient_id'],
                'preleve_le' => $donnees['preleve_le'] ?? now(),
                'note' => $donnees['note'] ?? null,
                'prescripteur_id' => app(ContexteClinique::class)->employe()?->id,
                'cree_par_id' => $request->user()?->id,
                ...isset($donnees['priorite']) ? ['priorite' => $donnees['priorite']] : [],
            ]);

            $panels = PanelLabo::whereIn('code', $donnees['panels'])->with('parametres')->get()->keyBy('code');

            // Dans l'ordre demandé (celui des cases du formulaire), pas
            // celui de l'index SQL.
            foreach ($donnees['panels'] as $code) {
                $panel = $panels[$code];
                $analysePanel = $analyse->panels()->create([
                    'panel_labo_id' => $panel->id,
                    'code' => $panel->code,
                    'nom' => $panel->nom,
                    'tube' => $panel->tube,
                ]);

                foreach ($panel->parametres as $parametre) {
                    $analyse->resultats()->create([
                        'analyse_panel_id' => $analysePanel->id,
                        'position' => $parametre->position,
                        'parametre' => $parametre->nom,
                        'unite' => $parametre->unite,
                        'borne_basse' => $parametre->borne_basse,
                        'borne_haute' => $parametre->borne_haute,
                    ]);
                }
            }

            return $analyse;
        });

        return new AnalyseResource($analyse->load($this->relations()));
    }

    public function show(Analyse $analyse): AnalyseResource
    {
        $this->authorize('view', $analyse);

        return new AnalyseResource($analyse->load($this->relations()));
    }

    public function update(ModifierAnalyseRequest $request, Analyse $analyse): AnalyseResource
    {
        $this->authorize('update', $analyse);

        // Validée, l'analyse est figée, sa note comprise (23.10.2026)
        if ($analyse->estValidee()) {
            throw ValidationException::withMessages(['note' => 'Une analyse validée est figée, sa note comprise.']);
        }

        $analyse->update($request->validated());

        return new AnalyseResource($analyse->load($this->relations()));
    }

    /** @return array<int|string, mixed> */
    public static function relations(): array
    {
        return [
            'patient.client',
            'prescripteur' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'validePar' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'panels',
            'resultats.panel',
        ];
    }
}
