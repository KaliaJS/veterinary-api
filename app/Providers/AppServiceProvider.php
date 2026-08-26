<?php

namespace App\Providers;

use App\Auth\ContexteClinique;
use App\Models\JetonApi;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ContexteClinique::class);

        // API en jetons purs : les routes web/session du package
        // passkeys ne sont pas enregistrées, ses actions sont
        // invoquées par nos contrôleurs.
        Passkeys::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(JetonApi::class);

        RateLimiter::for('auth', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute(10)->by($email.'|'.$request->ip());
        });
    }
}
