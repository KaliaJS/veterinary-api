<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PieceNumerotee;
use App\Enums\StatutDevis;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Devis\EnregistrerDevisRequest;
use App\Http\Requests\V1\Devis\ModifierDevisRequest;
use App\Http\Resources\V1\DevisResource;
use App\Models\Devis;
use App\Services\Numeroteur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Devis (docs/data-model.md §7) — niveau organisation. Seul un
 * brouillon se modifie et se supprime ; le filtre de statut comprend
 * `expire`, dérivé d'un envoyé hors validité (jamais stocké).
 */
class DevisController extends Controller
{
    public function __construct(private Numeroteur $numeroteur) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Devis::class);

        $statut = $request->string('statut')->toString();

        $devis = Devis::query()
            ->with(['lignes', 'patient.client'])
            ->when($statut === StatutDevis::EXPIRE, fn (Builder $requete) => $requete
                ->where('statut', StatutDevis::Envoye)
                ->whereDate('valide_jusquau', '<', aujourdhui()))
            ->when($statut === StatutDevis::Envoye->value, fn (Builder $requete) => $requete
                ->where('statut', StatutDevis::Envoye)
                ->where(fn (Builder $clause) => $clause
                    ->whereNull('valide_jusquau')
                    ->orWhereDate('valide_jusquau', '>=', aujourdhui())))
            ->when(in_array($statut, [StatutDevis::Brouillon->value, StatutDevis::Accepte->value, StatutDevis::Refuse->value], true),
                fn (Builder $requete) => $requete->where('statut', $statut))
            ->when($request->integer('patient_id'), fn (Builder $requete, int $patient) => $requete->where('patient_id', $patient))
            ->when($request->filled('q'), function (Builder $requete) use ($request): void {
                $terme = '%'.$request->string('q')->trim()->lower().'%';
                $requete->where(fn (Builder $clause) => $clause
                    ->whereRaw('lower(numero) like ?', [$terme])
                    ->whereRaw('lower(motif) like ?', [$terme], 'or')
                    ->orWhereHas('patient', fn (Builder $patient) => $patient->whereRaw('lower(nom) like ?', [$terme])));
            })
            ->latest('emis_le')
            ->latest('id')
            ->paginate($this->parPage($request));

        return DevisResource::collection($devis);
    }

    public function store(EnregistrerDevisRequest $request): DevisResource
    {
        $this->authorize('create', Devis::class);

        $donnees = $request->validated();

        $devis = DB::transaction(function () use ($donnees): Devis {
            $devis = Devis::create([
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::Devis),
                'emis_le' => aujourdhui(),
                'patient_id' => $donnees['patient_id'],
                'motif' => $donnees['motif'] ?? 'Devis de soins',
                'remise_pct' => $donnees['remise_pct'] ?? 0,
                'alea_pct' => $donnees['alea_pct'] ?? 0,
                'validite_jours' => $donnees['validite_jours'] ?? 30,
                'acompte_pct' => $donnees['acompte_pct'] ?? 0,
                'conditions' => $donnees['conditions'] ?? null,
                'statut' => StatutDevis::Brouillon,
            ]);

            $this->enregistrerLignes($devis, $donnees['lignes']);

            return $devis;
        });

        return new DevisResource($devis->load(['lignes', 'patient.client']));
    }

    public function show(Devis $devis): DevisResource
    {
        $this->authorize('view', $devis);

        return new DevisResource($devis->load(['lignes', 'patient.client']));
    }

    public function update(ModifierDevisRequest $request, Devis $devis): DevisResource
    {
        $this->authorize('update', $devis);

        if ($devis->statut !== StatutDevis::Brouillon) {
            throw ValidationException::withMessages(['statut' => 'Seul un brouillon se modifie.']);
        }

        $donnees = $request->validated();

        DB::transaction(function () use ($devis, $donnees): void {
            $devis->update(collect($donnees)->only([
                'patient_id', 'motif', 'remise_pct', 'alea_pct',
                'validite_jours', 'acompte_pct', 'conditions',
            ])->all());

            if (isset($donnees['lignes'])) {
                $devis->lignes()->delete();
                $this->enregistrerLignes($devis, $donnees['lignes']);
            }
        });

        return new DevisResource($devis->load(['lignes', 'patient.client']));
    }

    /** Suppression tolérée : le brouillon seulement. */
    public function destroy(Devis $devis): Response
    {
        $this->authorize('delete', $devis);

        if ($devis->statut !== StatutDevis::Brouillon) {
            throw ValidationException::withMessages([
                'statut' => 'Seul un brouillon se supprime, un devis envoyé se refuse ou expire.',
            ]);
        }

        $devis->delete();

        return response()->noContent();
    }

    /** @param list<array<string, mixed>> $lignes */
    private function enregistrerLignes(Devis $devis, array $lignes): void
    {
        foreach ($lignes as $ligne) {
            $devis->lignes()->create([
                'libelle' => $ligne['libelle'],
                'quantite' => $ligne['quantite'] ?? 1,
                'prix_chf' => $ligne['prix_chf'],
                'type' => $ligne['type'],
                'produit_id' => $ligne['produit_id'] ?? null,
            ]);
        }
    }
}
