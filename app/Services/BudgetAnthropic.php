<?php

namespace App\Services;

use App\Models\SuggestionDiagnostique;
use Carbon\CarbonImmutable;

/**
 * Le budget mensuel de la plateforme chez Anthropic (23.10.2026) : la
 * dépense est estimée par appel (rédactions de comptes rendus,
 * suggestions diagnostiques), le mois en cours. Au-delà, ces appels
 * attendent le mois suivant ; le plafond de la Console d'Anthropic reste
 * la dernière barrière.
 */
final class BudgetAnthropic
{
    public function atteint(): bool
    {
        $budget = config('services.anthropic.budget_usd');

        return $budget !== null && $this->depense() >= (float) $budget;
    }

    /** Dépense estimée du mois, en dollars. */
    public function depense(): float
    {
        $debut = CarbonImmutable::now('Europe/Zurich')->startOfMonth()->utc();

        $redactions = CompteurMensuel::valeur('anthropic:redactions')
            + CompteurMensuel::valeur('anthropic:essais:redaction');
        $suggestions = SuggestionDiagnostique::withoutGlobalScopes()->where('created_at', '>=', $debut)->count()
            + CompteurMensuel::valeur('anthropic:essais:suggestion');

        return $redactions * (float) config('services.anthropic.cout_redaction_usd')
            + $suggestions * (float) config('services.anthropic.cout_suggestion_usd');
    }

    /**
     * Un nouvel essai d'un appel (tâche reprise après une panne ou un délai
     * dépassé) peut être facturé : compté à part, le mois en cours, en base
     * (24.10.2026).
     */
    public static function nouvelEssai(string $type): void
    {
        CompteurMensuel::ajouter('anthropic:essais:'.$type, 1);
    }
}
