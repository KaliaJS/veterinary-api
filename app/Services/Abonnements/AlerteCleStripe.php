<?php

namespace App\Services\Abonnements;

use App\Mail\CleStripeRefusee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Stripe refuse la clé de la plateforme (absente, 401, 403) en prélevant un
 * abonnement : la plateforme est prévenue par e-mail, une fois par jour tant
 * que le refus dure (26.10.2026). Un e-mail qui ne part pas ne fait rien
 * échouer.
 */
class AlerteCleStripe
{
    public function signaler(int $statut): void
    {
        $adresse = config('plateforme.alerte_email');
        $jour = CarbonImmutable::now('Europe/Zurich')->toDateString();
        if (! $adresse || ! Cache::add('alerte:cle-stripe:'.$jour, true, now()->addDays(2))) {
            return;
        }

        try {
            Mail::to($adresse)->send(new CleStripeRefusee($statut));
        } catch (Throwable $e) {
            report($e);
            // Pas partie : la place du jour se libère, le prochain refus réessaie
            Cache::forget('alerte:cle-stripe:'.$jour);
        }
    }
}
