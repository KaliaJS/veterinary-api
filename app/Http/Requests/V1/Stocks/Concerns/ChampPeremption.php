<?php

namespace App\Http\Requests\V1\Stocks\Concerns;

use Carbon\CarbonImmutable;

/**
 * La péremption circule au format du front « MM.YYYY » et se stocke en
 * date (premier jour du mois) — docs/data-model.md §14.
 */
trait ChampPeremption
{
    /** @return list<string> */
    protected function reglesPeremption(): array
    {
        return ['nullable', 'string', 'regex:/^(0[1-9]|1[0-2])\.\d{4}$/'];
    }

    protected function peremptionLe(?string $peremption): ?CarbonImmutable
    {
        if ($peremption === null) {
            return null;
        }

        [$mois, $annee] = explode('.', $peremption);

        return CarbonImmutable::create((int) $annee, (int) $mois, 1);
    }
}
