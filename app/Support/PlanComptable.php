<?php

namespace App\Support;

use App\Enums\VatRate;
use App\Models\Societe;

/**
 * Le plan comptable de l'export (09.11.2026) : les comptes du plan PME
 * suisse par défaut, et les codes TVA de Banana, chaque société pouvant
 * les changer (Réglages, Comptabilité). Les montants partent TTC : les
 * codes TVA de la comptabilité doivent être « TVA comprise ».
 */
final class PlanComptable
{
    /** Les comptes par défaut (plan comptable PME) */
    public const array COMPTES = [
        'caisse' => '1000',
        'banque' => '1020',
        // Cartes, TWINT et acomptes en ligne : l'argent passe par Stripe (ou le terminal) avant la banque
        'transfert' => '1090',
        'clients' => '1100',
        'prestations' => '3400',
        'marchandises' => '3200',
    ];

    /** @return array<string, string> les codes TVA par défaut, par taux (normal, réduit) */
    public static function codesTvaParDefaut(): array
    {
        return [VatRate::Normal->value => 'V81', VatRate::Reduced->value => 'V26'];
    }

    /** @return array{comptes: array<string, string>, codes_tva: array<string, string>} */
    public static function pour(?Societe $societe): array
    {
        $propre = $societe->plan_comptable ?? [];

        return [
            'comptes' => array_merge(self::COMPTES, array_filter((array) ($propre['comptes'] ?? []), fn ($v): bool => is_string($v) && $v !== '')),
            'codes_tva' => array_merge(self::codesTvaParDefaut(), array_filter((array) ($propre['codes_tva'] ?? []), fn ($v): bool => is_string($v) && $v !== '')),
        ];
    }

    /** Le code TVA d'un taux (8.1, 2.6) ; un ancien taux sans code : vide */
    public static function codeTva(array $plan, ?float $taux): string
    {
        foreach (VatRate::cases() as $rate) {
            if ($taux !== null && abs($rate->percent() - $taux) < 0.001) {
                return $plan['codes_tva'][$rate->value] ?? '';
            }
        }

        return '';
    }
}
