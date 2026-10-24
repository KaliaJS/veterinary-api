<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DecisionAnnulation;
use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\RendezVous\AnnulerRendezVousRequest;
use App\Http\Requests\V1\RendezVous\ChangerStatutRequest;
use App\Http\Resources\V1\FactureResource;
use App\Http\Resources\V1\RendezVousResource;
use App\Models\RendezVous;
use App\Services\CreationFacture;
use App\Services\MutationRendezVous;
use App\Services\Paiements\AcomptesRendezVous;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie d'un rendez-vous. `annule` est terminal — jamais de
 * suppression, jamais de retour en arrière ; le passage en `salle`
 * mémorise l'heure d'arrivée ; le no-show ne part que de `prevu` ou
 * `salle`, hors tampon. Chaque geste est audité en un lot.
 *
 * Le passage à `fini` FACTURE l'acte dérivé du type (une seule facture
 * par RDV — la réouverture retrouve l'existante), sauf tampon (jamais
 * facturé) ou `sans_facture` (« Terminer sans générer »). La facture
 * est un effet système : elle naît même sans le domaine facturation,
 * mais la réponse ne l'expose qu'à qui peut la voir.
 */
class RendezVousStatutController extends Controller
{
    public function __construct(
        private MutationRendezVous $mutation,
        private CreationFacture $creationFacture,
    ) {}

    public function changer(ChangerStatutRequest $request, RendezVous $rendezVous): RendezVousResource
    {
        $this->authorize('update', $rendezVous);

        if ($rendezVous->status === StatutRendezVous::Annule) {
            throw ValidationException::withMessages(['status' => 'Un rendez-vous annulé ne change plus de statut.']);
        }

        $statut = StatutRendezVous::from($request->validated('status'));

        if ($statut === StatutRendezVous::Fini && ($geste = $rendezVous->gesteEnAttente())) {
            throw ValidationException::withMessages(['status' => $geste]);
        }

        $attributs = ['status' => $statut];

        if ($statut === StatutRendezVous::Salle && $rendezVous->arrived_at === null) {
            $attributs['arrived_at'] = now();
        }

        $this->mutation->appliquer($rendezVous, $attributs, $request->user());

        $facture = $statut === StatutRendezVous::Fini && ! $request->boolean('sans_facture')
            ? $this->creationFacture->depuisRendezVous($rendezVous, $request->user())
            : null;

        $resource = new RendezVousResource($rendezVous);

        if ($facture !== null && Gate::allows('facturation')) {
            $resource->additional(['facture' => new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']))]);
        }

        return $resource;
    }

    public function nonVenu(Request $request, RendezVous $rendezVous): RendezVousResource
    {
        $this->authorize('update', $rendezVous);

        if ($rendezVous->estTampon()
            || ! in_array($rendezVous->status, [StatutRendezVous::Prevu, StatutRendezVous::Salle], true)) {
            throw ValidationException::withMessages([
                'status' => 'Le non-venu ne se marque que sur un rendez-vous prévu ou arrivé, hors tampon.',
            ]);
        }

        $this->mutation->appliquer($rendezVous, ['status' => StatutRendezVous::Noshow], $request->user());

        return new RendezVousResource($rendezVous);
    }

    public function annuler(AnnulerRendezVousRequest $request, RendezVous $rendezVous): RendezVousResource
    {
        $this->authorize('update', $rendezVous);

        if ($rendezVous->status === StatutRendezVous::Annule) {
            throw ValidationException::withMessages(['status' => 'Ce rendez-vous est déjà annulé.']);
        }

        $motif = trim((string) $request->validated('motif', ''));

        // Réservé en ligne avec un acompte : la clinique choisit ce qu'elle
        // rend (tout par défaut). Rendu d'abord : si le prestataire refuse,
        // rien n'est annulé et l'on peut réessayer (le rendu n'est jamais fait deux fois)
        // L'un et l'autre, ou rien (24.10.2026) : une décision d'acompte n'est
        // jamais notée pour un rendez-vous resté prévu
        DB::transaction(function () use ($rendezVous, $request, $motif): void {
            app(AcomptesRendezVous::class)->surAnnulationDeLaClinique(
                $rendezVous,
                DecisionAnnulation::tryFrom((string) $request->validated('acompte')) ?? DecisionAnnulation::Complet,
            );
            $this->mutation->appliquer(
                $rendezVous,
                ['status' => StatutRendezVous::Annule],
                $request->user(),
                entreesSupplementaires: $motif === '' ? [] : ['motifAnnulation' => [null, $motif]],
            );
        });

        return new RendezVousResource($rendezVous);
    }
}
