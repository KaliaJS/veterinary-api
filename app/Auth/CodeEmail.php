<?php

namespace App\Auth;

use App\Models\User;
use App\Notifications\CodeEmailNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Connexion par code e-mail à 8 chiffres (secours de la passkey), et
 * inscription par la même voie : un e-mail inconnu reçoit aussi un
 * code, et sa vérification crée le compte (sans clinique). Le code est
 * haché en cache, à usage unique, valable 10 minutes, 5 essais de
 * vérification par période (comptés avant la vérification) et 20 par
 * jour ; au-delà, le code est effacé. La vraie API n'expose jamais le code (le
 * `demoCode` du front est un artefact de démo).
 *
 * Contrôle et consommation sont séparés : la voie de connexion
 * contrôle le code, résout le rattachement clinique, puis seulement
 * consomme — un rattachement multiple sans choix ne brûle pas le code.
 */
class CodeEmail
{
    private const int VALIDITE_MINUTES = 10;

    private const int ESSAIS_MAX = 5;

    /** Essais par adresse et par jour, tous codes confondus */
    private const int ESSAIS_JOUR = 20;

    public function __construct(private CreationCompte $comptes) {}

    /**
     * Compte connu ou non : le code part vers l'adresse donnée. Avec
     * `$lienConnexion`, l'e-mail porte aussi un lien qui ouvre le front
     * et vérifie le code d'un clic — adresse et code dans le fragment
     * (#), jamais transmis à un serveur, effacés par le front à la lecture.
     */
    public function envoyer(string $email, bool $lienConnexion = true): void
    {
        // Huit chiffres (23.10.2026) : cent fois plus long à deviner que six
        $code = (string) random_int(10000000, 99999999);

        Cache::put($this->cle($email), Hash::make($code), now()->addMinutes(self::VALIDITE_MINUTES));

        $lien = $lienConnexion
            ? rtrim((string) config('app.frontend_url'), '/').'/?connexion=1#email='.rawurlencode($email).'&code='.$code
            : null;

        $notification = new CodeEmailNotification($code, self::VALIDITE_MINUTES, $lien);
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->notify($notification);
        } else {
            Notification::route('mail', $email)->notify($notification);
        }
    }

    /**
     * Contrôle le code sans le consommer : un essai raté compte dans la
     * limite, un essai réussi laisse le code en place jusqu'à consommer().
     * E-mail inconnu et code juste : le compte est créé (e-mail vérifié
     * par le code même) — sans rattachement, il n'y a pas de choix de
     * clinique à résoudre, la voie consomme dans la foulée.
     *
     * @throws ValidationException
     */
    public function controler(string $email, string $code, bool $parLaSession = false): User
    {
        // L'essai est compté AVANT la vérification : des essais envoyés en
        // même temps ne passent pas tous le contrôle avant que le compteur
        // ne bouge. Au-delà de la limite, le code est effacé.
        $essais = RateLimiter::increment($this->limiteur($email), self::VALIDITE_MINUTES * 60);
        $essaisDuJour = RateLimiter::increment($this->limiteurJour($email), 86400);

        if ($essais > self::ESSAIS_MAX || $essaisDuJour > self::ESSAIS_JOUR) {
            Cache::forget($this->cle($email));

            throw ValidationException::withMessages([
                'code' => 'Trop de tentatives. Demandez un nouveau code.',
            ]);
        }

        $hache = Cache::get($this->cle($email));

        if (! $hache || ! Hash::check($code, $hache)) {
            if ($essais >= self::ESSAIS_MAX) {
                Cache::forget($this->cle($email));
            }

            throw ValidationException::withMessages([
                'code' => 'Code invalide ou expiré.',
            ]);
        }

        $user = User::where('email', $email)->first() ?? $this->comptes->creer($email, emailVerifie: true);

        // Un code reçu et saisi prouve l'adresse, y compris pour un compte
        // inscrit par passkey qui ne l'avait pas encore prouvée. Ce qui a été
        // posé sur ce compte avant la preuve (passkeys, sessions) peut venir
        // de quelqu'un d'autre qui a pris l'adresse d'avance : effacé
        // (23.10.2026). Le titulaire de l'adresse ajoute ensuite sa passkey.
        // Prouvée depuis la session du compte (espace, Réglages), c'est son
        // titulaire qui la prouve : rien n'est effacé.
        if ($user->email_verified_at === null) {
            if (! $parLaSession) {
                $user->passkeys()->delete();
                $user->tokens()->delete();
            }
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    /** Usage unique : le code disparaît et le compteur d'essais repart. */
    public function consommer(string $email): void
    {
        Cache::forget($this->cle($email));
        RateLimiter::clear($this->limiteur($email));
    }

    /**
     * Contrôle puis consommation en un temps (hors choix de clinique).
     *
     * @throws ValidationException
     */
    public function verifier(string $email, string $code): User
    {
        $user = $this->controler($email, $code);

        $this->consommer($email);

        return $user;
    }

    private function limiteur(string $email): string
    {
        return 'code-email:'.mb_strtolower($email);
    }

    private function limiteurJour(string $email): string
    {
        return 'code-email-jour:'.mb_strtolower($email);
    }

    private function cle(string $email): string
    {
        return 'auth:code-email:'.hash('sha256', mb_strtolower($email));
    }
}
