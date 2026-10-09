<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Enums\StatutPaiement;
use App\Http\Controllers\Api\V1\Concerns\GesteDeCaisse;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Facturation\PayerFactureRequest;
use App\Http\Resources\V1\PaiementResource;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\TerminalPaiement;
use App\Paiements\ErreurPaiement;
use App\Paiements\PasserellePaiement;
use App\Services\Paiements\PaiementsCaisse;
use Illuminate\Validation\ValidationException;

/**
 * Paiement d'une facture par Stripe (docs/data-model.md §6) : la caisse
 * lance la tentative (terminal ou TWINT), puis interroge son état
 * jusqu'à l'issue ; elle peut l'annuler. Réussie, la tentative encaisse
 * la facture, renvoyée avec elle.
 */
class PaiementCaisseController extends Controller
{
    use GesteDeCaisse;

    public function __construct(private PaiementsCaisse $paiements) {}

    public function store(PayerFactureRequest $request, Facture $facture): PaiementResource
    {
        $this->authorize('update', $facture);
        $this->assurerCliniqueDEmission($facture);
        $this->assurerFactureDue($facture);

        $paiement = $this->sansErreurStripe(fn () => $request->validated('moyen') === 'tpe'
            ? $this->paiements->parTerminal($facture, $this->terminal($request->integer('terminal_id')), $request->user())
            : $this->paiements->parTwint($facture, $request->user()));

        return $this->ressource($paiement);
    }

    /** État courant (relu chez Stripe tant qu'il attend). */
    public function show(Paiement $paiement): PaiementResource
    {
        return $this->ressource($this->sansErreurStripe(fn () => $this->paiements->synchroniser($paiement)));
    }

    public function annuler(Paiement $paiement): PaiementResource
    {
        return $this->ressource($this->sansErreurStripe(fn () => $this->paiements->annuler($paiement)));
    }

    /** Mode test, lecteur simulé : présente une carte de test. */
    public function simulerCarte(Paiement $paiement, PasserellePaiement $passerelle): PaiementResource
    {
        $terminal = $paiement->terminal;

        if (! $terminal || ! str_starts_with((string) $terminal->modele, 'simulated') || $paiement->statut !== StatutPaiement::EnAttente) {
            throw ValidationException::withMessages(['paiement' => 'Seul un paiement en attente sur un lecteur simulé peut être simulé.']);
        }

        $this->sansErreurStripe(fn () => $passerelle->simulerCarte($terminal->stripe_reader_id));

        return $this->show($paiement);
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
