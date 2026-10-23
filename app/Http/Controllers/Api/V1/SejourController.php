<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Hospitalisation\AdmettreSejourRequest;
use App\Http\Requests\V1\Hospitalisation\ModifierSejourRequest;
use App\Http\Resources\V1\SejourResource;
use App\Models\Clinique;
use App\Models\Sejour;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Séjours d'hospitalisation de la clinique du jeton (docs/data-model.md
 * §8). L'écran ne montre que les séjours en cours ; les séjours sortis
 * restent au dossier (`?historique=1`) — jamais de suppression, comme
 * les rendez-vous annulés. L'admission pose le plan de soins d'entrée
 * (parité front) ; la sortie est un geste dédié.
 */
class SejourController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Sejour::class);

        $sejours = Sejour::query()
            ->unless($request->boolean('historique'), fn ($requete) => $requete->enCours())
            ->when($request->integer('patient_id'), fn ($requete, int $patient) => $requete->where('patient_id', $patient))
            ->with($this->relations())
            ->orderBy('box')
            ->orderByDesc('admis_le')
            ->paginate($this->parPage($request));

        return SejourResource::collection($sejours);
    }

    public function store(AdmettreSejourRequest $request): SejourResource
    {
        $this->authorize('create', Sejour::class);

        $donnees = $request->validated();

        $sejour = DB::transaction(function () use ($request, $donnees): Sejour {
            $sejour = Sejour::create([
                ...$donnees,
                'veterinaire_id' => $donnees['veterinaire_id']
                    ?? app(ContexteClinique::class)->employe()?->id,
                'admis_le' => now(),
                'sortie_prevue' => $donnees['sortie_prevue'] ?? 'À définir',
                'cree_par_id' => $request->user()?->id,
            ]);

            $jour = aujourdhui()->toDateString();
            foreach ($this->planDeSoinsDEntree() as [$minutes, $acte]) {
                $sejour->soins()->create(['jour' => $jour, 'prevu_a' => $minutes, 'acte' => $acte]);
            }

            return $sejour;
        });

        return new SejourResource($sejour->load($this->relations()));
    }

    public function show(Sejour $sejour): SejourResource
    {
        $this->authorize('view', $sejour);

        return new SejourResource($sejour->load($this->relations()));
    }

    public function update(ModifierSejourRequest $request, Sejour $sejour): SejourResource
    {
        $this->authorize('update', $sejour);

        if ($sejour->estSorti()) {
            throw ValidationException::withMessages(['sejour' => 'Un séjour sorti ne se modifie plus.']);
        }

        $sejour->update($request->validated());

        return new SejourResource($sejour->load($this->relations()));
    }

    /**
     * Plan de soins d'entrée (hgSave du front) : trois contrôles calés
     * sur l'heure locale de la clinique, arrondis à la demi-heure.
     *
     * @return list<array{int, string}>
     */
    private function planDeSoinsDEntree(): array
    {
        $fuseau = Clinique::find(app(ContexteClinique::class)->cliniqueId())?->fuseau_horaire ?? 'Europe/Zurich';
        $locale = CarbonImmutable::now($fuseau);
        $base = min(1350, (int) ceil(($locale->hour * 60 + $locale->minute + 45) / 30) * 30);

        return [
            [$base, 'Contrôle T° / constantes d\'entrée'],
            [min(1380, $base + 120), 'Contrôle perfusion / hydratation'],
            [min(1410, $base + 300), 'Proposition repas + sortie hygiénique'],
        ];
    }

    /** @return array<int|string, mixed> */
    private function relations(): array
    {
        return [
            'patient.client',
            'veterinaire' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'soins.produit',
            'constantes',
        ];
    }
}
