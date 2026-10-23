<?php

namespace Tests;

use App\Auth\ContexteClinique;
use App\Auth\ContexteProprietaire;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Aucun appel HTTP réel pendant les tests : chaque fournisseur
     * (Stripe, Claude, Deepgram, MedReg, Refdata, Cloudflare) passe par
     * un double ou par Http::fake ; un appel oublié échoue au lieu de
     * partir sur le réseau.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
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
