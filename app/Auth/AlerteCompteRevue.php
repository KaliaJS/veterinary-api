<?php

namespace App\Auth;

use App\Mail\EssaisCompteRevue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Essais faux sur l'adresse de revue d'Apple (28.10.2026) : la plateforme
 * est prévenue, une fois par jour au plus. Un e-mail qui ne part pas, ou un
 * cache en panne, ne fait rien échouer.
 */
class AlerteCompteRevue
{
    public function signaler(string $adresse): void
    {
        $alerte = config('plateforme.alerte_email');
        if (! $alerte) {
            return;
        }
        $cle = 'alerte:compte-revue:'.CarbonImmutable::now('Europe/Zurich')->toDateString();

        try {
            if (! Cache::add($cle, true, now()->addDays(2))) {
                return;
            }
            Mail::to($alerte)->send(new EssaisCompteRevue($adresse));
        } catch (Throwable $e) {
            report($e);
            Cache::forget($cle);
        }
    }
}
