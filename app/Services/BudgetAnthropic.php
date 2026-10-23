<?php

namespace App\Services;

use App\Models\EnregistrementConsultation;
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

        $redactions = (int) EnregistrementConsultation::withoutGlobalScopes()->where('updated_at', '>=', $debut)->sum('redactions');
        $suggestions = SuggestionDiagnostique::withoutGlobalScopes()->where('created_at', '>=', $debut)->count();

        return $redactions * (float) config('services.anthropic.cout_redaction_usd')
            + $suggestions * (float) config('services.anthropic.cout_suggestion_usd');
    }
}
