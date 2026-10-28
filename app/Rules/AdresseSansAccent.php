<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Une adresse e-mail de compte s'écrit sans accent ni caractère spécial
 * (28.10.2026) : en MySQL (utf8mb4_unicode_ci), « paul@gmaíl.com » vaut
 * « paul@gmail.com », alors que PHP les distingue ; une adresse accentuée
 * prendrait l'invitation ou le compte d'une autre. Un domaine accentué
 * s'écrit sous sa forme « xn-- ».
 */
class AdresseSansAccent implements ValidationRule
{
    public const string MESSAGE = 'Écrivez l’adresse e-mail sans accent ni caractère spécial.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/[^\x21-\x7E]/', trim($value)) === 1) {
            $fail(self::MESSAGE);
        }
    }
}
