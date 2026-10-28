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

        // ASCII seulement : un caractère comme le signe Kelvin, qui devient
        // « k » en minuscules, n'ouvre pas le code fixe (28.10.2026)
        $email = trim($email);
        if (preg_match('/[^\x20-\x7E]/', $email) === 1) {
            return null;
        }

        return strtolower($email) === $adresse ? $code : null;
    }

    /** `$email` est l'adresse de revue, avec un réglage valable : aucun e-mail ne lui part. */
    public function estAdresseDeRevue(string $email): bool
    {
        return $this->codePour($email) !== null;
    }

    /**
     * L'adresse de revue ou une graphie qui s'y ramène en minuscules (signe
     * Kelvin pour « k »…), qu'une base en utf8mb4_unicode_ci confondrait
     * avec elle (28.10.2026).
     */
    public function ressemble(string $email): bool
    {
        $adresse = mb_strtolower(trim((string) config('plateforme.revue_apple.email')));
        if ($adresse === '' || preg_match('/^\d{8}$/', trim((string) config('plateforme.revue_apple.code'))) !== 1) {
            return false;
        }
        $email = trim($email);
        $compatible = \Normalizer::normalize($email, \Normalizer::FORM_KC);

        return mb_strtolower($email) === $adresse || mb_strtolower(is_string($compatible) ? $compatible : $email) === $adresse;
    }

    /** Une graphie voisine de l'adresse de revue, pas l'adresse elle-même : ni code fixe, ni code envoyé. */
    public function estSosie(string $email): bool
    {
        return $this->ressemble($email) && $this->codePour($email) === null;
    }
}
