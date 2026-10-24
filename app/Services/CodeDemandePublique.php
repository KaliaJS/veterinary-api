<?php

namespace App\Services;

use App\Mail\CodeDemandeRendezVous;
use App\Models\Clinique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Demande de rendez-vous sans compte (23.10.2026) : l'adresse donnée est
 * prouvée par un code à 6 chiffres avant que la demande parte. Personne
 * ne fait plus envoyer d'e-mails de la clinique à l'adresse d'un autre.
 * Code haché en cache, 15 minutes, 5 essais comptés avant la
 * vérification, effacé au-delà ; consommé quand la demande est déposée.
 */
class CodeDemandePublique
{
    private const int VALIDITE_MINUTES = 15;

    private const int ESSAIS_MAX = 5;

    public function envoyer(string $email, Clinique $clinique): void
    {
        $code = (string) random_int(100000, 999999);

        Cache::put($this->cle($email, $clinique), Hash::make($code), now()->addMinutes(self::VALIDITE_MINUTES));
        RateLimiter::clear($this->limiteur($email, $clinique));

        Mail::to($email)->send(new CodeDemandeRendezVous($clinique, $code, self::VALIDITE_MINUTES));
    }

    /** @throws ValidationException */
    public function verifier(string $email, Clinique $clinique, string $code): void
    {
        $essais = RateLimiter::increment($this->limiteur($email, $clinique), self::VALIDITE_MINUTES * 60);
        $hache = Cache::get($this->cle($email, $clinique));

        if ($essais > self::ESSAIS_MAX || ! $hache || ! Hash::check($code, $hache)) {
            if ($essais >= self::ESSAIS_MAX) {
                Cache::forget($this->cle($email, $clinique));
            }

            throw ValidationException::withMessages([
                'code' => $essais > self::ESSAIS_MAX ? 'Trop de tentatives. Demandez un nouveau code.' : 'Code invalide ou expiré.',
            ]);
        }
    }

    /**
     * Usage unique, même pour deux envois simultanés du même code : seul le
     * premier le prend (ajout atomique au cache), 24.10.2026.
     */
    public function consommer(string $email, Clinique $clinique): bool
    {
        $hache = Cache::get($this->cle($email, $clinique));
        $premier = is_string($hache)
            && Cache::add('demande-publique:utilise:'.hash('sha256', $hache), true, now()->addMinutes(self::VALIDITE_MINUTES));
        Cache::forget($this->cle($email, $clinique));
        RateLimiter::clear($this->limiteur($email, $clinique));

        return $premier;
    }

    private function cle(string $email, Clinique $clinique): string
    {
        return 'demande-publique:code:'.$clinique->id.':'.hash('sha256', mb_strtolower(trim($email)));
    }

    private function limiteur(string $email, Clinique $clinique): string
    {
        return 'demande-publique:essais:'.$clinique->id.':'.mb_strtolower(trim($email));
    }
}
