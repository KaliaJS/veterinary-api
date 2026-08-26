<?php

namespace App\Providers;

use App\Auth\ContexteClinique;
use App\Enums\DomainePermission;
use App\Models\JetonApi;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ContexteClinique::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(JetonApi::class);

        // Un Gate par domaine de permission. La vérité vit en base :
        // le middleware `clinique` charge les permissions de l'employé
        // à chaque requête, le contexte les tient le temps de celle-ci.
        foreach (DomainePermission::cases() as $domaine) {
            Gate::define(
                $domaine->value,
                fn (User $user): bool => $this->app->make(ContexteClinique::class)->permet($domaine),
            );
        }

        RateLimiter::for('auth', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute(10)->by($email.'|'.$request->ip());
        });
    }
}
