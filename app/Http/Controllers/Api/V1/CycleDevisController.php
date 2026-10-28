<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\CanalDevis;
use App\Enums\PieceNumerotee;
use App\Enums\StatutDevis;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DevisResource;
use App\Mail\RelanceDevis;
use App\Models\Devis;
use App\Services\Numeroteur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cycle de vie d'un devis (docs/data-model.md §7) : envoi (canal choisi
 * selon le compte espace client du propriétaire, validité posée),
 * acceptation (signature électronique ou « bon pour accord » papier
 * selon le canal), refus, duplication (repart en brouillon).
 */
class CycleDevisController extends Controller
{
    public function __construct(private Numeroteur $numeroteur) {}

    public function envoyer(Request $request, Devis $devis): DevisResource
    {
        $this->authorize('update', $devis);

        if ($devis->statut !== StatutDevis::Brouillon) {
            throw ValidationException::withMessages(['statut' => 'Seul un brouillon s’envoie.']);
        }

        $proprietaireALEspaceClient = $devis->patient->client->user_id !== null;

        $devis->update([
            'statut' => StatutDevis::Envoye,
            'canal' => $proprietaireALEspaceClient ? CanalDevis::Portail : CanalDevis::Papier,
            'valide_jusquau' => aujourdhui()->addDays($devis->validite_jours),
        ]);

        return new DevisResource($devis->load(['lignes', 'patient.client', 'clinique.societe']));
    }

    /**
     * Relance d'un devis envoyé et encore valable (28.10.2026) : sur
     * l'espace client, un e-mail au propriétaire, une fois par jour au plus ;
     * un devis papier se relance par téléphone (rien ne part).
     */
    public function relancer(Request $request, Devis $devis): JsonResponse
    {
        $this->authorize('update', $devis);

        if ($devis->statut !== StatutDevis::Envoye || $devis->estExpire()) {
            throw ValidationException::withMessages(['statut' => 'Seul un devis envoyé, encore valable, se relance.']);
        }
        $client = $devis->patient?->client()->with('user')->first();
        $adresse = $devis->canal === CanalDevis::Portail ? ($client?->user?->email ?: $client?->email) : null;
        if (! $adresse) {
            return response()->json(['data' => ['relance' => 'telephone']]);
        }
        if (! Cache::add('devis-relance:'.$devis->id.':'.aujourdhui()->toDateString(), true, now()->addDays(2))) {
            throw ValidationException::withMessages(['relance' => 'Ce devis a déjà été relancé aujourd’hui.']);
        }
        try {
            Mail::to($adresse)->send(new RelanceDevis($devis->load(['clinique', 'patient'])));
        } catch (Throwable $e) {
            Cache::forget('devis-relance:'.$devis->id.':'.aujourdhui()->toDateString());
            report($e);
            abort(503, 'La relance n’est pas partie : réessayez dans quelques minutes.');
        }

        return response()->json(['data' => ['relance' => 'email']]);
    }

    public function accepter(Request $request, Devis $devis): DevisResource
    {
        $this->authorize('update', $devis);

        if ($devis->estExpire()) {
            throw ValidationException::withMessages(['statut' => 'Ce devis est expiré : dupliquez-le pour repartir d’un brouillon.']);
        }

        if ($devis->statut !== StatutDevis::Envoye) {
            throw ValidationException::withMessages(['statut' => 'Seul un devis envoyé s’accepte.']);
        }

        // L'équipe enregistre l'accord : ce n'est jamais une signature
        // électronique du client (23.10.2026), on dit qui l'a enregistré
        $parQui = app(ContexteClinique::class)->employe()->nom ?? 'la clinique';

        $devis->update([
            'statut' => StatutDevis::Accepte,
            'signe_le' => now(),
            'signe_par' => $devis->canal === CanalDevis::Portail
                ? 'Accord du propriétaire enregistré par '.$parQui
                : 'Signature papier « bon pour accord », classée au dossier, enregistrée par '.$parQui,
        ]);

        return new DevisResource($devis->load(['lignes', 'patient.client', 'clinique.societe']));
    }

    public function refuser(Request $request, Devis $devis): DevisResource
    {
        $this->authorize('update', $devis);

        if ($devis->statut !== StatutDevis::Envoye) {
            throw ValidationException::withMessages(['statut' => 'Seul un devis envoyé se refuse.']);
        }

        $devis->update(['statut' => StatutDevis::Refuse]);

        return new DevisResource($devis->load(['lignes', 'patient.client', 'clinique.societe']));
    }

    /** Repart en brouillon daté du jour — validité, canal, signature et factures remis à zéro. */
    public function dupliquer(Request $request, Devis $devis): DevisResource
    {
        $this->authorize('create', Devis::class);

        $copie = DB::transaction(function () use ($devis): Devis {
            $copie = Devis::create([
                'clinique_id' => app(ContexteClinique::class)->cliniqueId(),
                'numero' => $this->numeroteur->attribuer(PieceNumerotee::Devis),
                'emis_le' => aujourdhui(),
                'patient_id' => $devis->patient_id,
                'motif' => $devis->motif,
                'remise_pct' => $devis->remise_pct,
                'alea_pct' => $devis->alea_pct,
                'validite_jours' => $devis->validite_jours,
                'acompte_pct' => $devis->acompte_pct,
                'conditions' => $devis->conditions,
                'statut' => StatutDevis::Brouillon,
            ]);

            foreach ($devis->lignes as $ligne) {
                $copie->lignes()->create([
                    'libelle' => $ligne->libelle,
                    'quantite' => $ligne->quantite,
                    'prix_chf' => $ligne->prix_chf,
                    'type' => $ligne->type,
                    'produit_id' => $ligne->produit_id,
                ]);
            }

            return $copie;
        });

        return new DevisResource($copie->load(['lignes', 'patient.client', 'clinique.societe']));
    }
}
