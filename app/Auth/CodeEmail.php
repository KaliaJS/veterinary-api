<?php

namespace App\Auth;

use App\Models\User;
use App\Notifications\CodeEmailNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Connexion par code e-mail à 6 chiffres (secours de la passkey).
 * Le code est haché en cache, à usage unique, valable 10 minutes,
 * 5 essais de vérification par période. La vraie API n'expose jamais
 * le code (le `demoCode` du front est un artefact de démo).
 */
class CodeEmail
{
    private const int VALIDITE_MINUTES = 10;

    private const int ESSAIS_MAX = 5;

    /** N'envoie rien pour un e-mail inconnu — réponse identique côté HTTP. */
    public function envoyer(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return;
        }

        $code = (string) random_int(100000, 999999);

        Cache::put($this->cle($email), Hash::make($code), now()->addMinutes(self::VALIDITE_MINUTES));

        $user->notify(new CodeEmailNotification($code, self::VALIDITE_MINUTES));
    }

    /** @throws ValidationException */
    public function verifier(string $email, string $code): User
    {
        $limiteur = 'code-email:'.mb_strtolower($email);

        if (RateLimiter::tooManyAttempts($limiteur, self::ESSAIS_MAX)) {
            throw ValidationException::withMessages([
                'code' => 'Trop de tentatives — demandez un nouveau code.',
            ]);
        }

        $hache = Cache::get($this->cle($email));
        $user = User::where('email', $email)->first();

        if (! $hache || ! $user || ! Hash::check($code, $hache)) {
            RateLimiter::hit($limiteur, self::VALIDITE_MINUTES * 60);

            throw ValidationException::withMessages([
                'code' => 'Code invalide ou expiré.',
            ]);
        }

        Cache::forget($this->cle($email));
        RateLimiter::clear($limiteur);

        return $user;
    }

    private function cle(string $email): string
    {
        return 'auth:code-email:'.hash('sha256', mb_strtolower($email));
    }
}
