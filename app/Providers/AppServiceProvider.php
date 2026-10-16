<?php

namespace App\Providers;

use App\Auth\ContexteClinique;
use App\Auth\ContexteProprietaire;
use App\Enums\DomainePermission;
use App\Models\JetonApi;
use App\Models\User;
use App\Paiements\PasserelleAbonnement;
use App\Paiements\PasserelleAbonnementStripe;
use App\Paiements\PasserellePaiement;
use App\Paiements\PasserelleStripe;
use App\Services\Domaines\FournisseurCloudflare;
use App\Services\Domaines\FournisseurDomaines;
use App\Services\Domaines\FournisseurManuel;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ContexteClinique::class);
        $this->app->scoped(ContexteProprietaire::class);

        // Paiements en caisse : Stripe (Terminal, TWINT), remplacé par un
        // double dans les tests
        $this->app->singleton(PasserellePaiement::class, fn () => new PasserelleStripe(
            new StripeClient(['api_key' => config('services.stripe.secret') ?: null]),
            config('services.stripe.webhook_secret'),
        ));

        // Abonnement SaaS des organisations : Stripe Billing, remplacé par
        // un double dans les tests
        $this->app->singleton(PasserelleAbonnement::class, fn () => new PasserelleAbonnementStripe(
            new StripeClient(['api_key' => config('services.stripe.secret') ?: null]),
        ));

        // Domaines personnalisés des sites : fournisseur choisi par configuration
        $this->app->bind(FournisseurDomaines::class, fn ($app) => config('sites.domaines.fournisseur') === 'cloudflare'
            ? $app->make(FournisseurCloudflare::class)
            : $app->make(FournisseurManuel::class));
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
