<?php

use App\Auth\ContexteClinique;
use App\Models\Clinique;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

if (! function_exists('aujourdhui')) {
    /**
     * Le jour du calendrier de la clinique (23.10.2026) : celle donnée, ou
     * celle du contexte, sinon Zurich. L'application compte en UTC ; entre
     * minuit et deux heures à Zurich, `today()` donnerait encore la veille.
     * Rendu à minuit en UTC, comme les dates de la base : les comparaisons
     * et les écarts en jours restent justes. Du même type que `today()`.
     */
    function aujourdhui(?Clinique $clinique = null): Carbon
    {
        $clinique ??= app(ContexteClinique::class)->clinique();
        $fuseau = $clinique?->fuseau_horaire ?: 'Europe/Zurich';

        return Carbon::parse(CarbonImmutable::now($fuseau)->toDateString(), config('app.timezone'));
    }
}

if (! function_exists('minuitLocal')) {
    /**
     * L'instant (UTC) où commence ce jour du calendrier à la clinique
     * (24.10.2026) : pour comparer un jour choisi à des horodatages de la
     * base (factures payées, mouvements du registre).
     */
    function minuitLocal(DateTimeInterface|string $jour, ?Clinique $clinique = null): CarbonImmutable
    {
        $clinique ??= app(ContexteClinique::class)->clinique();
        $fuseau = $clinique?->fuseau_horaire ?: 'Europe/Zurich';
        $date = $jour instanceof DateTimeInterface ? $jour->format('Y-m-d') : CarbonImmutable::parse($jour)->toDateString();

        return CarbonImmutable::parse($date, $fuseau)->utc();
    }
}
