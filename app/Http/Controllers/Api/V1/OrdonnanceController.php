<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\PieceNumerotee;
use App\Enums\StatutOrdonnance;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Ordonnances\EnregistrerOrdonnanceRequest;
use App\Http\Resources\V1\OrdonnanceResource;
use App\Models\Ordonnance;
use App\Models\Produit;
use App\Services\GestionnaireStock;
use App\Services\Numeroteur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registre des ordonnances (docs/data-model.md §12). La signature crée
 * l'ordonnance ET sort le stock — une unité par ligne, clinique du
 * jeton, sans blocage en rupture (l'écart réel est tracé, parité
 * front). Jamais de modification après signature ; la seule suppression
 * est l'annulation d'une signature dans sa fenêtre, qui restaure le
 * stock par mouvements inverses.
 */
class OrdonnanceController extends Controller
{
    /** Fenêtre d'annulation d'une signature — couvre l'undo de 10 s du front plus la latence. */
    private const int FENETRE_ANNULATION_SECONDES = 20;

    public function __construct(
        private Numeroteur $numeroteur,
        private GestionnaireStock $stock,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Ordonnance::class);

        $ordonnances = Ordonnance::query()
            ->with(['lignes.produit', 'employe' => fn ($requete) => $requete->withoutGlobalScope('clinique')])
            ->when($request->enum('statut', StatutOrdonnance::class), fn (Builder $requete, StatutOrdonnance $statut) => $requete->where('statut', $statut))
            ->when($request->integer('patient_id'), fn (Builder $requete, int $patient) => $requete->where('patient_id', $patient))
            ->when($request->filled('q'), function (Builder $requete) use ($request): void {
                $terme = '%'.$request->string('q')->trim()->lower().'%';
                $requete->where(fn (Builder $clause) => $clause
                    ->whereRaw('lower(numero) like ?', [$terme])
                    ->orWhereHas('patient', fn (Builder $patient) => $patient->whereRaw('lower(nom) like ?', [$terme]))
                    ->orWhereHas('lignes.produit', fn (Builder $produit) => $produit->whereRaw('lower(nom) like ?', [$terme])));
            })
            ->latest('signee_le')
            ->latest('id')
            ->paginate($this->parPage($request));

        return OrdonnanceResource::collection($ordonnances);
    }

    public function store(EnregistrerOrdonnanceRequest $request): OrdonnanceResource
    {
        $this->authorize('create', Ordonnance::class);

        $donnees = $request->validated();
        $contexte = app(ContexteClinique::class);

        $ordonnance = DB::transaction(function () use ($request, $donnees, $contexte): Ordonnance {
            $ordonnance = Ordonnance::create([
                'clinique_id' => $contexte->cliniqueId(),
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::Ordonnance),
                'patient_id' => $donnees['patient_id'],
                'employe_id' => $contexte->employe()->id,
                'signee_le' => today(),
                'renouvellements' => $donnees['renouvellements'] ?? 0,
                'statut' => StatutOrdonnance::Active,
                'note' => $donnees['note'] ?? null,
            ]);

            foreach ($donnees['lignes'] as $ligne) {
                $ordonnance->lignes()->create([
                    'produit_id' => $ligne['produit_id'],
                    'posologie' => $ligne['posologie'],
                    'duree' => $ligne['duree'] ?? 'Selon besoin',
                ]);

                // Une unité par ligne — sans blocage en rupture : le
                // journal trace l'écart réel (parité front).
                $this->stock->sortir(
                    Produit::findOrFail($ligne['produit_id']),
                    1,
                    'Sortie — ordonnance '.$ordonnance->numero,
                    $request->user(),
                    $ordonnance,
                );
            }

            return $ordonnance;
        });

        return new OrdonnanceResource($ordonnance->load(['lignes.produit', 'employe', 'patient']));
    }

    public function show(Ordonnance $ordonnance): OrdonnanceResource
    {
        $this->authorize('view', $ordonnance);

        return new OrdonnanceResource($ordonnance->load([
            'lignes.produit',
            'employe' => fn ($requete) => $requete->withoutGlobalScope('clinique'),
            'patient.client',
        ]));
    }

    /**
     * Annulation d'une signature dans sa fenêtre — seule suppression
     * tolérée du registre : même signataire, aucun geste depuis, stock
     * restauré par mouvements inverses. Si l'ordonnance annulée était un
     * renouvellement, la précédente redevient active (parité front).
     */
    public function destroy(Request $request, Ordonnance $ordonnance): Response
    {
        $this->authorize('delete', $ordonnance);

        $estDansLaFenetre = $ordonnance->statut === StatutOrdonnance::Active
            && $ordonnance->employe_id === app(ContexteClinique::class)->employe()?->id
            && $ordonnance->created_at->gt(now()->subSeconds(self::FENETRE_ANNULATION_SECONDES));

        if (! $estDansLaFenetre) {
            throw ValidationException::withMessages([
                'ordonnance' => 'Une ordonnance signée ne se supprime pas — clôturez-la. Seule l’annulation immédiate d’une signature est tolérée.',
            ]);
        }

        DB::transaction(function () use ($request, $ordonnance): void {
            $this->stock->inverserMouvementsDe(
                $ordonnance,
                'Annulation ordonnance '.$ordonnance->numero,
                $request->user(),
            );

            $ordonnance->precedente?->update(['statut' => StatutOrdonnance::Active]);

            $ordonnance->delete();
        });

        return response()->noContent();
    }
}
