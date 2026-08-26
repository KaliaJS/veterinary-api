<?php

namespace Tests;

use App\Auth\ContexteClinique;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
