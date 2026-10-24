<?php

namespace Tests;

use App\Auth\ContexteClinique;
use App\Auth\ContexteProprietaire;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Aucun appel HTTP réel pendant les tests : chaque fournisseur
     * (Stripe, Claude, Deepgram, MedReg, Refdata, Cloudflare) passe par
     * un double ou par Http::fake ; un appel oublié échoue au lieu de
     * partir sur le réseau.
     *
     * Heure piège (24.10.2026) : `composer test` joue la suite à l'heure
     * réelle, puis une seconde fois au prochain 1er janvier à 0 h 30 à
     * Zurich (`HORLOGE=nouvel-an`), quand UTC est encore au 31 décembre :
     * changement de jour, de mois et d'année d'un coup. Toujours à venir,
     * comme les certificats des doubles (App Store), valables depuis
     * l'heure réelle. `HORLOGE` accepte aussi un instant précis.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        if ($horloge = getenv('HORLOGE')) {
            $this->travelTo($horloge === 'nouvel-an'
                ? CarbonImmutable::now('Europe/Zurich')->addYear()->startOfYear()->setTime(0, 30)
                : CarbonImmutable::parse($horloge));
        }
    }

    /**
     * Chaque requête de test repart d'un état d'authentification
     * neuf : sans cela, le guard Sanctum et le contexte clinique
     * (singleton « scoped ») survivent d'une requête à l'autre dans
     * un même test — ce qu'aucun vrai déploiement ne fait.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->app->resolved('auth')) {
            $this->app['auth']->forgetGuards();
        }

        $this->app->make(ContexteClinique::class)->oublier();
        $this->app->make(ContexteProprietaire::class)->oublier();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
