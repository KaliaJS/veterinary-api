<?php

namespace App\Services\Domaines;

use App\Mail\JetonCloudflareRefuse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Cloudflare refuse le jeton de l'API (401, 403) : la plateforme est prévenue
 * par e-mail, une fois par jour tant que le refus dure (26.10.2026), que ce
 * soit en vérifiant, en ajoutant ou en retirant un domaine. Un e-mail qui ne
 * part pas ne fait rien échouer.
 */
class AlerteJetonCloudflare
{
    public function signaler(int $statut): void
    {
        $adresse = config('plateforme.alerte_email');
        $jour = CarbonImmutable::now('Europe/Zurich')->toDateString();
        if (! $adresse || ! Cache::add('alerte:jeton-cloudflare:'.$jour, true, now()->addDays(2))) {
            return;
        }

        try {
            Mail::to($adresse)->send(new JetonCloudflareRefuse($statut));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
