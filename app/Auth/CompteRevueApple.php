<?php

namespace App\Auth;

/**
 * Compte de revue d'Apple (TestFlight externe, 27.10.2026) : les testeurs
 * d'Apple ne lisent pas nos e-mails, l'adresse de revue se connecte donc avec
 * un code fixe de 8 chiffres (secret de Laravel Cloud, à changer quand on
 * veut). Sans réglage valable, rien ne change.
 */
class CompteRevueApple
{
    /** Le code fixe si `$email` est l'adresse de revue et que le réglage est valable, sinon null. */
    public function codePour(string $email): ?string
    {
        $adresse = mb_strtolower(trim((string) config('plateforme.revue_apple.email')));
        $code = trim((string) config('plateforme.revue_apple.code'));

        if ($adresse === '' || preg_match('/^\d{8}$/', $code) !== 1) {
            return null;
        }

        return mb_strtolower(trim($email)) === $adresse ? $code : null;
    }

    /** `$email` est l'adresse de revue, avec un réglage valable : aucun e-mail ne lui part. */
    public function estAdresseDeRevue(string $email): bool
    {
        return $this->codePour($email) !== null;
    }
}
