<?php

namespace App\Services;

use App\Enums\MoyenPaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Facture;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Encaissement d'une facture (docs/data-model.md §6) : la sortie de
 * stock des lignes médicament se fait UNE seule fois (drapeau
 * `stock_sorti`) — au paiement, ou au « payer plus tard » (le stock
 * sort quand même, la facture reste due).
 */
class EncaissementFacture
{
    public function __construct(private GestionnaireStock $stock) {}

    public function encaisser(Facture $facture, MoyenPaiement $moyen, ?User $auteur): Facture
    {
        return DB::transaction(function () use ($facture, $moyen, $auteur): Facture {
            $this->sortirStock($facture, $auteur);

            $facture->update([
                'statut' => StatutFacture::Payee,
                'moyen_paiement' => $moyen,
                'payee_le' => now(),
            ]);

            return $facture;
        });
    }

    /** « Payer plus tard » — le stock sort quand même. */
    public function payerPlusTard(Facture $facture, ?User $auteur): Facture
    {
        DB::transaction(function () use ($facture, $auteur): void {
            $this->sortirStock($facture, $auteur);
        });

        return $facture;
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
                'Sortie — facture '.$facture->numero,
                $auteur,
                $facture,
            );
        }

        $facture->update(['stock_sorti' => true]);
    }
}
