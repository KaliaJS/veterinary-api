<?php

namespace App\Support;

/**
 * La TVA par taux d'une pièce (03.11.2026) : pour chaque taux, le hors
 * taxe, la TVA et le TTC. Les prix sont TTC : la TVA s'extrait du total
 * de chaque taux, arrondie au centime. Un taux nul (société non
 * assujettie) ne figure pas : la pièce ne montre alors aucune TVA.
 * L'écran les affiche tels quels, sans calcul.
 *
 * @phpstan-type Row array{rate: float, excl_vat: float, vat: float, incl_vat: float}
 */
final class VatBreakdown
{
    /**
     * Une remise (taux null) se répartit au prorata des montants positifs de
     * chaque taux (vérification du 03.11.2026) ; le dernier taux prend
     * l'arrondi, pour que la somme reste celle de la remise. Une part
     * d'acompte (`separate`) garde la TVA arrondie de sa facture d'acompte :
     * la facture finale en déduit exactement ce qui y a été facturé, et
     * acompte plus finale font la TVA du devis (04.11.2026).
     *
     * @param  iterable<array{rate: float|null, amount: float, separate?: bool}>  $amounts  montants TTC et leur taux
     * @return list<Row>
     */
    public static function of(iterable $amounts): array
    {
        $totals = [];
        $positive = [];
        $separate = [];
        $discount = 0.0;
        foreach ($amounts as $amount) {
            if ($amount['rate'] === null) {
                $discount += $amount['amount'];

                continue;
            }
            $key = number_format($amount['rate'], 1, '.', '');
            $totals[$key] ??= 0.0;
            if ($amount['separate'] ?? false) {
                $separate[$key][] = $amount['amount'];

                continue;
            }
            $totals[$key] += $amount['amount'];
            $positive[$key] = ($positive[$key] ?? 0.0) + max(0.0, $amount['amount']);
        }

        $base = array_sum($positive);
        if ($discount != 0.0 && $base > 0) {
            ksort($positive, SORT_NUMERIC);
            $left = round($discount, 2);
            $keys = array_keys(array_filter($positive, fn (float $p): bool => $p > 0));
            foreach ($keys as $i => $key) {
                $share = $i === count($keys) - 1 ? $left : round($discount * $positive[$key] / $base, 2);
                $totals[$key] += $share;
                $left = round($left - $share, 2);
            }
        }

        ksort($totals, SORT_NUMERIC);

        $rows = [];
        foreach ($totals as $key => $main) {
            $rate = (float) $key;
            if ($rate <= 0) {
                continue;
            }
            $main = round($main, 2);
            $vat = self::vatIn($main, $rate);
            $gross = $main;
            foreach ($separate[$key] ?? [] as $part) {
                $vat += self::vatIn(round($part, 2), $rate);
                $gross += round($part, 2);
            }
            $gross = round($gross, 2);
            $vat = round($vat, 2);
            $rows[] = ['rate' => $rate, 'excl_vat' => round($gross - $vat, 2), 'vat' => $vat, 'incl_vat' => $gross];
        }

        return $rows;
    }

    /** La TVA contenue dans un montant TTC, arrondie au centime. */
    private static function vatIn(float $gross, float $rate): float
    {
        return round($gross - $gross / (1 + $rate / 100), 2);
    }

    /**
     * Les détails de plusieurs pièces additionnés taux par taux (journal, panier) :
     * chaque pièce garde ses arrondis.
     *
     * @param  iterable<list<Row>>  $breakdowns
     * @return list<Row>
     */
    public static function sum(iterable $breakdowns): array
    {
        $totals = [];
        foreach ($breakdowns as $breakdown) {
            foreach ($breakdown as $row) {
                $key = number_format($row['rate'], 1, '.', '');
                $totals[$key] ??= ['rate' => $row['rate'], 'excl_vat' => 0.0, 'vat' => 0.0, 'incl_vat' => 0.0];
                foreach (['excl_vat', 'vat', 'incl_vat'] as $field) {
                    $totals[$key][$field] = round($totals[$key][$field] + $row[$field], 2);
                }
            }
        }
        ksort($totals, SORT_NUMERIC);

        return array_values($totals);
    }

    /** @param  list<Row>  $breakdown */
    public static function vat(array $breakdown): float
    {
        return round(array_sum(array_column($breakdown, 'vat')), 2);
    }
}
