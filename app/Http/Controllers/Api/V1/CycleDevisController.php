<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\CanalDevis;
use App\Enums\PieceNumerotee;
use App\Enums\PushType;
use App\Enums\StatutDevis;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DevisResource;
use App\Mail\DevisEnvoye;
use App\Mail\RelanceDevis;
use App\Models\Devis;
use App\Services\Notifications\Push;
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

    /**
     * Envoi : sur l'espace client, un e-mail prévient le propriétaire (31.10.2026,
     * décision de Jim) ; s'il ne part pas, le devis reste envoyé et la réponse
     * le dit (`meta.email`), l'équipe relancera.
     */
    public function envoyer(Request $request, Devis $devis): DevisResource
    {
        $this->authorize('update', $devis);

        if ($devis->statut !== StatutDevis::Brouillon) {
            throw ValidationException::withMessages(['statut' => 'Seul un brouillon s’envoie.']);
        }
        // Un brouillon peut se commencer sans patient ni prestation, pas partir ainsi
        if ($devis->patient_id === null) {
            throw ValidationException::withMessages(['patient_id' => 'Choisissez le patient avant d’envoyer le devis.']);
        }
        if (! $devis->lignes()->exists()) {
            throw ValidationException::withMessages(['lignes' => 'Ajoutez au moins une prestation avant d’envoyer le devis.']);
        }

        $client = $devis->patient->client()->with('user')->first();
        $proprietaireALEspaceClient = $client?->user_id !== null;

        $devis->update([
            'statut' => StatutDevis::Envoye,
            'canal' => $proprietaireALEspaceClient ? CanalDevis::Portail : CanalDevis::Papier,
            'valide_jusquau' => aujourdhui()->addDays($devis->validite_jours),
            'envoye_le' => now(),
        ]);

        $email = null;
        $adresse = $proprietaireALEspaceClient ? ($client->user?->email ?: $client->email) : null;
        if ($adresse) {
            try {
                Mail::to($adresse)->send(new DevisEnvoye($devis->load(['clinique', 'patient'])));
                $email = 'envoye';
            } catch (Throwable $e) {
                report($e);
                $email = 'echec';
            }
        }

        // Sur son téléphone aussi, s'il a l'app (02.11.2026)
        if ($proprietaireALEspaceClient) {
            Push::to([$client->user_id], PushType::Quote, $devis->clinique->nom, 'Devis reçu pour '.$devis->patient->nom, '/espace/documents?devis='.$devis->id);
        }

        return (new DevisResource($devis->load(['lignes', 'patient.client', 'clinique.societe'])))->additional(['meta' => ['email' => $email]]);
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
            $devis->update(['relance_le' => now()]);
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

        $this->repondre($devis, [
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

        $this->repondre($devis, [
            'statut' => StatutDevis::Refuse,
            'refuse_le' => now(),
            'refuse_par' => 'Refus enregistré par '.(app(ContexteClinique::class)->employe()->nom ?? 'la clinique'),
        ]);

        return new DevisResource($devis->load(['lignes', 'patient.client', 'clinique.societe']));
    }

    /**
     * La réponse enregistrée par l'équipe, seulement si le devis attend
     * encore : le client qui répond en ligne au même instant n'est jamais
     * écrasé (comme dans son espace).
     *
     * @param  array<string, mixed>  $valeurs
     */
    private function repondre(Devis $devis, array $valeurs): void
    {
        $ecrits = Devis::query()->whereKey($devis->id)->where('statut', StatutDevis::Envoye)->update($valeurs);
        if ($ecrits !== 1) {
            throw ValidationException::withMessages(['statut' => 'Le client vient de répondre à ce devis : rechargez-le.']);
        }
        $devis->refresh();
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
