<?php

if (! function_exists('chf')) {
    /**
     * Un montant en francs comme l'app l'écrit partout (24.10.2026) :
     * « CHF 1’234.50 », apostrophe typographique des milliers, point
     * décimal. Les centimes se divisent avant : chf($centimes / 100).
     */
    function chf(float|int|string|null $francs): string
    {
        return 'CHF '.number_format((float) $francs, 2, '.', '’');
    }
}
