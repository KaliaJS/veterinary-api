<?php

namespace App\Rules;

use App\Auth\ContexteClinique;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * On commande depuis la clinique (décision de Jim, 28.10.2026) : une
 * clinique demandée doit être celle du jeton ; pour une autre, on change
 * de clinique.
 */
class CliniqueOuverte implements ValidationRule
{
    public const string MESSAGE = 'Une commande se passe depuis la clinique qui la reçoit : ouvrez cette clinique pour commander.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && (int) $value !== app(ContexteClinique::class)->cliniqueId()) {
            $fail(self::MESSAGE);
        }
    }
}
