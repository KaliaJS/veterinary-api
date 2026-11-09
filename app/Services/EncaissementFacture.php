<?php

namespace App\Services;

use App\Enums\MoyenPaiement;
use App\Enums\SettlementMethod;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\User;
use App\Services\Facturation\Settlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Encaissement d'une facture (docs/data-model.md §6) : la sortie de
 * stock des lignes médicament se fait UNE seule fois (drapeau
 * `stock_sorti`) — au paiement, ou au « payer plus tard » (le stock
 * sort quand même, la facture reste due). La facture est relue sous
 * verrou (23.10.2026) : deux encaissements simultanés n'en font jamais
 * deux, ni deux sorties de stock ; qui a encaissé est noté.
 */
class EncaissementFacture
{
    public function __construct(private GestionnaireStock $stock, private Settlements $settlements) {}

    /**
     * Un règlement (09.11.2026) : le reste, ou un montant plus petit
     * (paiement partiel), daté du jour où l'argent est arrivé (un virement
     * reçu avant, sinon aujourd'hui). La facture est payée quand ses
     * règlements atteignent son total. Sans moyen : seulement une facture
     * déjà couverte par l'acompte payé en ligne.
     */
    public function encaisser(Facture $facture, ?MoyenPaiement $moyen, ?User $auteur, ?float $montant = null, ?CarbonImmutable $recuLe = null, ?string $reference = null, ?Paiement $paiement = null): Facture
    {
        return DB::transaction(function () use ($facture, $moyen, $auteur, $montant, $recuLe, $reference, $paiement): Facture {
            $this->verrouiller($facture);
            if (! $facture->estDue()) {
                throw ValidationException::withMessages(['facture' => 'Cette facture est déjà payée.']);
            }

            $facture->unsetRelation('settlements');
            $reste = $facture->loadMissing('lignes')->resteAPayer();

            if ($moyen === null && $reste > 0) {
                throw ValidationException::withMessages(['moyen_paiement' => 'Choisissez le moyen de paiement.']);
            }
            // Rien à encaisser (couverte par l'acompte en ligne, ou à 0 franc) : payée, sans règlement de plus
            if ($reste <= 0 && $montant === null) {
                $this->sortirStock($facture, $auteur);
                $facture->update(['statut' => StatutFacture::Payee, 'moyen_paiement' => $moyen, 'payee_le' => now(), 'encaissee_par_id' => $auteur?->id]);

                return $facture;
            }
            if ($moyen === null) {
                throw ValidationException::withMessages(['moyen_paiement' => 'Choisissez le moyen de paiement.']);
            }

            $montant = round($montant ?? $reste, 2);
            if ($montant <= 0) {
                throw ValidationException::withMessages(['montant' => $reste > 0 ? 'Le montant doit être plus grand que zéro.' : 'Il ne reste rien à payer sur cette facture.']);
            }
            if ($montant > $reste + 0.005) {
                throw ValidationException::withMessages(['montant' => 'Le montant dépasse le reste à payer ('.chf($reste).').']);
            }

            $this->sortirStock($facture, $auteur);
            $this->settlements->record($facture, SettlementMethod::fromPayment($moyen), $montant, $recuLe, $auteur, $reference, $paiement);

            return $facture->refresh();
        });
    }

    /** « Payer plus tard » — le stock sort quand même. */
    public function payerPlusTard(Facture $facture, ?User $auteur): Facture
    {
        DB::transaction(function () use ($facture, $auteur): void {
            $this->verrouiller($facture);
            $this->sortirStock($facture, $auteur);
        });

        return $facture;
    }

    /** Statut et stock tels qu'ils sont vraiment, la ligne tenue jusqu'à la fin. */
    private function verrouiller(Facture $facture): void
    {
        $tenue = Facture::query()->withoutGlobalScopes()->whereKey($facture->id)->lockForUpdate()->firstOrFail();
        $facture->forceFill(['statut' => $tenue->statut, 'stock_sorti' => $tenue->stock_sorti])->syncOriginalAttributes(['statut', 'stock_sorti']);
    }

    private function sortirStock(Facture $facture, ?User $auteur): void
    {
        if ($facture->stock_sorti) {
            return;
        }

        $lignes = $facture->lignes()
            ->where('type', TypeLigneFacture::Medicament)
            ->whereNotNull('produit_id')
            ->with('produit')
            ->get();

        foreach ($lignes as $ligne) {
            $this->stock->sortir(
                $ligne->produit,
                $ligne->quantite,
                'Sortie : facture '.$facture->numero,
                $auteur,
                $facture,
            );
        }

        $facture->update(['stock_sorti' => true]);
    }
}
