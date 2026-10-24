<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatutRendezVous;
use App\Enums\TypeRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\RendezVous\EnregistrerRendezVousRequest;
use App\Http\Requests\V1\RendezVous\ModifierRendezVousRequest;
use App\Http\Resources\V1\RendezVousResource;
use App\Models\LignePlanning;
use App\Models\Patient;
use App\Models\RendezVous;
use App\Services\CalculateurCreneaux;
use App\Services\MutationRendezVous;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aucun filtre clinique ici (scope global) et aucune contrainte
 * d'exclusivité d'intervalle : le chevauchement de rendez-vous est une
 * décision produit, résolu à l'affichage. Les statuts terminaux et le
 * cycle de vie sont portés par RendezVousStatutController.
 */
class RendezVousController extends Controller
{
    public function __construct(private MutationRendezVous $mutation) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RendezVous::class);

        // Les annulés restent dans la réponse (jamais supprimés) : le
        // front les écarte de la grille mais en garde la trace. `actifs`
        // ne garde que ce qui occupe la colonne (ni annulé, ni tampon) —
        // le bilan d'impact d'une absence lit `meta.total`.
        $rendezVous = RendezVous::query()
            ->avecDerives()
            ->when($request->date('du'), fn ($requete, $du) => $requete->where('ends_at', '>', $du))
            ->when($request->date('au'), fn ($requete, $au) => $requete->where('starts_at', '<', $au))
            ->when($request->integer('ligne_planning_id'), fn ($requete, $ligne) => $requete->where('ligne_planning_id', $ligne))
            ->when($request->boolean('actifs'), fn ($requete) => $requete
                ->where('status', '!=', StatutRendezVous::Annule)
                ->where('type', '!=', TypeRendezVous::Tampon))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate($this->parPage($request, 100));

        return RendezVousResource::collection($rendezVous);
    }

    public function store(EnregistrerRendezVousRequest $request, CalculateurCreneaux $calculateur): RendezVousResource
    {
        $this->authorize('create', RendezVous::class);

        $attributs = $this->mutation->preparerCreation($request->validated());

        $ligne = LignePlanning::findOrFail($attributs['ligne_planning_id']);

        if ($calculateur->ligneEstBloquee($ligne, CarbonImmutable::parse($attributs['starts_at']))) {
            throw ValidationException::withMessages([
                'ligne_planning_id' => 'Colonne bloquée par une absence validée du praticien.',
            ]);
        }

        return new RendezVousResource($this->mutation->creer($attributs, $request->user()));
    }

    public function show(RendezVous $rendezVous): RendezVousResource
    {
        $this->authorize('view', $rendezVous);

        return new RendezVousResource($rendezVous);
    }

    public function update(ModifierRendezVousRequest $request, RendezVous $rendezVous): RendezVousResource
    {
        $this->authorize('update', $rendezVous);

        if ($rendezVous->estTampon()) {
            throw ValidationException::withMessages(['type' => 'Un créneau tampon ne se modifie pas.']);
        }

        $donnees = $request->validated();

        $attributs = array_intersect_key($donnees, array_flip([
            'ligne_planning_id', 'salle_id', 'patient_id', 'patient_name',
            'species', 'owner_name', 'type', 'reason',
        ]));

        $dureeActuelle = (int) $rendezVous->starts_at->diffInMinutes($rendezVous->ends_at);
        $duree = (int) ($donnees['duree_minutes'] ?? $dureeActuelle);
        $debut = isset($donnees['starts_at'])
            ? CarbonImmutable::parse($donnees['starts_at'])
            : $rendezVous->starts_at->toImmutable();

        $attributs['starts_at'] = $debut;
        $attributs['ends_at'] = $debut->addMinutes($duree);

        if (array_key_exists('patient_id', $donnees) && $donnees['patient_id'] !== null) {
            $attributs = [...$attributs, ...Patient::findOrFail($donnees['patient_id'])->denormalisation()];
        } elseif (array_key_exists('patient_id', $donnees)) {
            $attributs['client_id'] = null;
        }

        // Parité front : un simple décalage n'audite que `startsAt`, le
        // `endsAt` qui suit le début sans changer la durée reste muet.
        $champsNonAudites = $duree === $dureeActuelle ? ['ends_at'] : [];

        $this->mutation->appliquer($rendezVous, $attributs, $request->user(), $champsNonAudites);

        return new RendezVousResource($rendezVous);
    }

    /**
     * Seule suppression tolérée : annuler une création dans sa fenêtre
     * d'undo (docs/data-model.md — arbitrage : 20 s, assez pour couvrir
     * l'undo de 10 s du front et la latence, sans laisser un trou hors
     * interface ; même auteur, aucun autre geste depuis). Tout le reste
     * passe par l'annulation statutaire.
     */
    public function destroy(Request $request, RendezVous $rendezVous): Response
    {
        $this->authorize('delete', $rendezVous);

        $audits = $rendezVous->audits()->orderBy('id')->get();
        $creation = $audits->first();

        $estDansLaFenetre = $creation !== null
            && $creation->champ === 'création'
            && $audits->pluck('batch_id')->unique()->count() === 1
            && $creation->user_id === $request->user()->id
            && $creation->created_at->gt(now()->subSeconds(20))
            // Vide : rien ne s'y est attaché (compte rendu signé, facture…), 24.10.2026
            && ! self::aDesPieces($rendezVous);

        if (! $estDansLaFenetre) {
            throw ValidationException::withMessages([
                'rendez_vous' => 'Un rendez-vous ne se supprime pas, annulez-le. Seule l’annulation immédiate d’une création est tolérée.',
            ]);
        }

        $rendezVous->delete();

        return response()->noContent();
    }

    /** Une pièce du dossier ou de la caisse rattachée au rendez-vous. */
    private static function aDesPieces(RendezVous $rendezVous): bool
    {
        return collect([
            'comptes_rendus', 'enregistrements_consultation', 'suggestions_diagnostiques', 'interventions',
            'examens_imagerie', 'sessions_teleconsultation', 'factures', 'demandes_rendez_vous',
        ])->contains(fn (string $table): bool => DB::table($table)->where('rendez_vous_id', $rendezVous->id)->exists());
    }
}
