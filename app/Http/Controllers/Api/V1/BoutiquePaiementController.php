<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Enums\MoyenPaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutPaiement;
use App\Http\Controllers\Api\V1\Concerns\GesteDeCaisse;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FactureResource;
use App\Http\Resources\V1\PaiementResource;
use App\Http\Resources\V1\TerminalPaiementResource;
use App\Models\CommandeBoutique;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\TerminalPaiement;
use App\Paiements\ErreurPaiement;
use App\Services\EncaissementFacture;
use App\Services\Paiements\PaiementsCaisse;
use App\Services\Paiements\TerminauxPaiement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Paiement d'une vente du comptoir depuis la boutique (28.10.2026), avec
 * le droit Stocks : terminal choisi par la boutique ou QR TWINT, issue
 * diffusée en temps réel ; espèces à la place ; vente non payée annulée,
 * gardée au journal, sans toucher au stock. Jamais une facture de
 * consultation : celle-là se paie à la caisse.
 */
class BoutiquePaiementController extends Controller
{
    use GesteDeCaisse;

    public function __construct(private PaiementsCaisse $paiements, private TerminauxPaiement $terminaux) {}

    /** Les ventes du comptoir restées dues (fenêtre perdue, page rechargée) : à reprendre ou annuler. */
    public function enAttente(): AnonymousResourceCollection
    {
        $this->authorize('create', CommandeBoutique::class);

        return FactureResource::collection(Facture::query()
            ->where('clinique_id', app(ContexteClinique::class)->cliniqueId())
            ->where('vente_comptoir', true)->where('statut', StatutFacture::Due)
            ->with(['lignes', 'prescriptions', 'decompte'])->latest('id')->limit(20)->get());
    }

    public function terminaux(): JsonResponse
    {
        $this->authorize('create', CommandeBoutique::class);

        return response()->json(['data' => TerminalPaiement::query()->orderBy('libelle')->get()
            ->map(fn (TerminalPaiement $terminal) => new TerminalPaiementResource($terminal, $this->terminaux->estEnLigne($terminal)))]);
    }

    public function payer(Request $request, Facture $facture): JsonResponse
    {
        $vente = $this->venteDue($facture);
        $donnees = $request->validate([
            'moyen' => ['required', Rule::in([MoyenPaiement::Tpe->value, MoyenPaiement::Twint->value])],
            'terminal_id' => ['required_if:moyen,tpe', 'nullable', 'integer'],
        ], [
            'moyen.required' => 'Choisissez la carte ou TWINT.',
            'moyen.in' => 'Choisissez la carte ou TWINT.',
            'terminal_id.required_if' => 'Choisissez le terminal de la boutique.',
        ]);

        $paiement = $this->sansErreurStripe(fn () => $donnees['moyen'] === MoyenPaiement::Tpe->value
            ? $this->paiements->parTerminal($vente, $this->terminal((int) $donnees['terminal_id']), $request->user())
            : $this->paiements->parTwint($vente, $request->user()));

        // Annulée pendant le lancement : la tentative tout juste armée est retirée
        if ($vente->refresh()->statut === StatutFacture::Annulee) {
            $this->sansErreurStripe(fn () => $this->paiements->annuler($paiement));
            throw ValidationException::withMessages(['vente' => 'Cette vente a été annulée.']);
        }

        return (new PaiementResource($paiement->load('terminal')))->response()->setStatusCode(201);
    }

    /** Relu une fois au retour du temps réel (l'issue manquée pendant une coupure). */
    public function afficher(Paiement $paiement): PaiementResource
    {
        $this->paiementDuComptoir($paiement);

        return $this->ressource($this->sansErreurStripe(fn () => $this->paiements->synchroniser($paiement)));
    }

    public function annulerPaiement(Paiement $paiement): PaiementResource
    {
        $this->paiementDuComptoir($paiement);

        return $this->ressource($this->sansErreurStripe(fn () => $this->paiements->annuler($paiement)));
    }

    /** La carte ou TWINT n'ont pas abouti : la vente se paie en espèces. */
    public function especes(Request $request, Facture $facture, EncaissementFacture $encaissement): FactureResource
    {
        $vente = $this->venteDue($facture);
        $this->sansTentativeEnCours($vente);
        $encaissement->encaisser($vente, MoyenPaiement::Especes, $request->user());

        return new FactureResource($vente->refresh()->load(['lignes', 'prescriptions', 'decompte']));
    }

    /**
     * Vente non payée annulée : gardée au journal, le stock n'a jamais bougé.
     * Depuis la boutique (Stocks) ou la caisse (Facturation).
     */
    public function annuler(Facture $facture): FactureResource
    {
        abort_unless(Gate::any([DomainePermission::Stocks->value, DomainePermission::Facturation->value]), 403, 'Action non autorisée pour votre profil.');
        $this->venteDuComptoir($facture);
        if ($facture->statut === StatutFacture::Payee) {
            throw ValidationException::withMessages(['vente' => 'Cette vente est payée : elle ne s’annule plus.']);
        }
        $this->sansTentativeEnCours($facture);

        $facture = DB::transaction(function () use ($facture): Facture {
            $tenue = Facture::query()->whereKey($facture->id)->lockForUpdate()->firstOrFail();
            // Payée pendant l'annulation (le client venait de valider)
            if ($tenue->statut === StatutFacture::Payee) {
                throw ValidationException::withMessages(['vente' => 'Cette vente vient d’être payée : elle ne s’annule plus.']);
            }
            $tenue->forceFill(['statut' => StatutFacture::Annulee, 'annulee_le' => $tenue->annulee_le ?? now()])->save();

            return $tenue;
        });

        return new FactureResource($facture->load(['lignes', 'prescriptions', 'decompte']));
    }

    private function venteDuComptoir(Facture $facture): Facture
    {
        abort_unless($facture->vente_comptoir && $facture->clinique_id === app(ContexteClinique::class)->cliniqueId(), 404);

        return $facture;
    }

    private function venteDue(Facture $facture): Facture
    {
        $this->authorize('create', CommandeBoutique::class);
        $this->venteDuComptoir($facture);
        $this->assurerFactureDue($facture);

        return $facture;
    }

    private function paiementDuComptoir(Paiement $paiement): void
    {
        $this->authorize('create', CommandeBoutique::class);
        $this->venteDuComptoir($paiement->facture);
    }

    /** Une tentative en cours est annulée d'abord ; refusée par Stripe parce que payée, la vente l'est. */
    private function sansTentativeEnCours(Facture $facture): void
    {
        if (! $facture->aUnPaiementEnCours()) {
            return;
        }
        try {
            $this->paiements->annulerTentatives($facture);
        } catch (ErreurPaiement $e) {
            throw ValidationException::withMessages(['paiements' => $e->getMessage()]);
        }
        if ($facture->refresh()->statut === StatutFacture::Payee) {
            throw ValidationException::withMessages(['vente' => 'Cette vente vient d’être payée.']);
        }
        // Stripe n'a pas pu l'annuler (le client valide à l'instant) : on attend son issue
        if ($facture->aUnPaiementEnCours()) {
            throw ValidationException::withMessages(['vente' => 'Un paiement est en cours de validation : attendez son issue.']);
        }
    }

    private function terminal(int $id): TerminalPaiement
    {
        $terminal = TerminalPaiement::query()->find($id);
        if (! $terminal || $terminal->clinique_id !== app(ContexteClinique::class)->cliniqueId()) {
            throw ValidationException::withMessages(['terminal_id' => 'Ce terminal n’appartient pas à la clinique.']);
        }

        return $terminal;
    }

    private function ressource(Paiement $paiement): PaiementResource
    {
        $paiement->load('terminal');
        if ($paiement->statut === StatutPaiement::Reussi) {
            $paiement->load(['facture.lignes', 'facture.prescriptions', 'facture.decompte']);
        }

        return new PaiementResource($paiement);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $appel
     * @return T
     */
    private function sansErreurStripe(callable $appel): mixed
    {
        try {
            return $appel();
        } catch (ErreurPaiement $e) {
            throw ValidationException::withMessages(['paiement' => $e->getMessage()]);
        }
    }
}
