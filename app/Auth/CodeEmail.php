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
 * Connexion par code e-mail à 6 chiffres (secours de la passkey), et
 * inscription par la même voie : un e-mail inconnu reçoit aussi un
 * code, et sa vérification crée le compte (sans clinique). Le code est
 * haché en cache, à usage unique, valable 10 minutes, 5 essais de
 * vérification par période. La vraie API n'expose jamais le code (le
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

    public function __construct(private CreationCompte $comptes) {}

    /**
     * Compte connu ou non : le code part vers l'adresse donnée. Avec
     * `$lienConnexion`, l'e-mail porte aussi un lien qui ouvre le front
     * et vérifie le code d'un clic — adresse et code dans le fragment
     * (#), jamais transmis à un serveur, effacés par le front à la lecture.
     */
    public function envoyer(string $email, bool $lienConnexion = true): void
    {
        $code = (string) random_int(100000, 999999);

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
    public function controler(string $email, string $code): User
    {
        $limiteur = $this->limiteur($email);

        if (RateLimiter::tooManyAttempts($limiteur, self::ESSAIS_MAX)) {
            throw ValidationException::withMessages([
                'code' => 'Trop de tentatives — demandez un nouveau code.',
            ]);
        }

        $hache = Cache::get($this->cle($email));

        if (! $hache || ! Hash::check($code, $hache)) {
            RateLimiter::hit($limiteur, self::VALIDITE_MINUTES * 60);

            throw ValidationException::withMessages([
                'code' => 'Code invalide ou expiré.',
            ]);
        }

        $user = User::where('email', $email)->first() ?? $this->comptes->creer($email, emailVerifie: true);

        // Un code reçu et saisi prouve l'adresse — y compris pour un
        // compte inscrit par passkey qui ne l'avait pas encore prouvée.
        if ($user->email_verified_at === null) {
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

    private function cle(string $email): string
    {
        return 'auth:code-email:'.hash('sha256', mb_strtolower($email));
    }
}
