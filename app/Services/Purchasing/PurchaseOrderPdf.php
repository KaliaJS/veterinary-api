<?php

namespace App\Services\Purchasing;

use App\Models\Clinique;
use App\Models\CommandeFournisseur;
use App\Models\CommandeFournisseurLigne;
use App\Models\ProductSource;
use App\Support\Pdf\SimplePdf;

/**
 * Le bon de commande en PDF, fait par le serveur pour partir par e-mail au
 * fournisseur (03.11.2026) : la clinique qui commande et où livrer, le
 * fournisseur, chaque article avec la référence chez lui, sa quantité et son
 * prix d'achat, le total. Les montants sont ceux des lignes, au centime.
 */
class PurchaseOrderPdf
{
    /** Article, référence, quantité, prix, total : la largeur utile de la page */
    private const array COLUMNS = [235, 92, 52, 60, 60];

    private const array ALIGNS = ['left', 'left', 'right', 'right', 'right'];

    public function render(CommandeFournisseur $order): string
    {
        $clinique = Clinique::withoutGlobalScopes()->with('societe')->findOrFail($order->clinique_id);
        $header = $clinique->enTeteDocument();
        $timezone = $clinique->fuseau_horaire ?: 'Europe/Zurich';
        $footer = implode(' · ', array_filter([$header['societe'] ?: $header['nom'], $header['ide'] ? 'IDE '.$header['ide'] : null]));
        $lines = $order->lignes()->with(['produit' => fn ($p) => $p->withoutGlobalScopes()])->orderBy('id')->get();
        $references = ProductSource::query()->where('supplier_id', $order->supplier_id)->whereIn('produit_id', $lines->pluck('produit_id'))->pluck('reference', 'produit_id');

        $pdf = new SimplePdf($footer);
        $pdf->line($header['nom'], 13, true, gap: 3);
        foreach (array_filter([$header['adresse'], $header['contact']]) as $info) {
            $pdf->line($info, 9, isGray: true, gap: 2);
        }
        $pdf->space(22)
            ->line('Bon de commande '.$order->numero, 18, true, gap: 6)
            ->line('Fournisseur : '.$order->fournisseur, 11, gap: 3)
            ->line('Livraison : '.trim($header['nom'].', '.$header['adresse'], ', '), 11, gap: 3)
            ->line('Date : '.now($timezone)->format('d.m.Y'), 11, gap: 14)
            ->line('ARTICLES COMMANDÉS ('.$lines->count().')', 8, true, isGray: true)
            ->rule(10)
            // En colonnes (04.11.2026) : un nom long passe à la ligne, la référence et la quantité restent entières
            ->row(['Article', 'Référence', 'Quantité', 'Prix', 'Total'], self::COLUMNS, self::ALIGNS, 8, true, true);

        $cents = 0;
        foreach ($lines as $line) {
            /** @var CommandeFournisseurLigne $line */
            $unit = (int) round((float) $line->cout * 100);
            $lineCents = (int) $line->quantite * $unit;
            $cents += $lineCents;
            $reference = $references[$line->produit_id] ?? $line->produit?->reference;
            $pdf->row([(string) ($line->produit->nom ?? 'Article'), (string) $reference, (string) (int) $line->quantite, self::chf($unit), self::chf($lineCents)], self::COLUMNS, self::ALIGNS);
        }
        $pdf->rule(10)
            ->line('Total HT (prix d’achat)', 12, true, right: self::chf($cents), gap: 16)
            ->line('Merci de confirmer la disponibilité et le délai. Référence : '.$order->numero.'.', 9, isGray: true);

        return $pdf->output();
    }

    private static function chf(int $cents): string
    {
        return 'CHF '.number_format($cents / 100, 2, '.', '’');
    }
}
