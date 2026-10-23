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
use App\Services\Diagnostic\Suggesteur;
use App\Services\Diagnostic\SuggesteurClaude;
use App\Services\Domaines\FournisseurCloudflare;
use App\Services\Domaines\FournisseurDomaines;
use App\Services\Domaines\FournisseurManuel;
use App\Services\Transcription\Redacteur;
use App\Services\Transcription\RedacteurClaude;
use App\Services\Transcription\Transcripteur;
use App\Services\Transcription\TranscripteurDeepgram;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
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
            config('services.stripe.webhook_secret_comptes'),
        ));

        // Abonnement SaaS des organisations : Stripe Billing, remplacé par
        // un double dans les tests
        $this->app->singleton(PasserelleAbonnement::class, fn () => new PasserelleAbonnementStripe(
            new StripeClient(['api_key' => config('services.stripe.secret') ?: null]),
        ));

        // Transcription des consultations : un fournisseur pour transcrire,
        // un pour rédiger le compte rendu, chacun remplaçable derrière son
        // interface (doubles dans les tests)
        $this->app->bind(Transcripteur::class, TranscripteurDeepgram::class);
        $this->app->bind(Redacteur::class, RedacteurClaude::class);
        $this->app->bind(Suggesteur::class, SuggesteurClaude::class);

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

        // E-mails : une saisie (nom d'un animal, message) ne devient jamais
        // un lien ou du HTML (« [Payer](https://…) » reste du texte)
        Markdown::withSecuredEncoding();

        // Un Gate par domaine de permission. La vérité vit en base :
        // le middleware `clinique` charge les permissions de l'employé
        // à chaque requête, le contexte les tient le temps de celle-ci.
        foreach (DomainePermission::cases() as $domaine) {
            Gate::define(
                $domaine->value,
                fn (User $user): bool => $this->app->make(ContexteClinique::class)->permet($domaine),
            );
        }

        // Connexion : par adresse ET par IP seule (une machine ne balaie pas
        // des milliers d'adresses en changeant d'adresse à chaque essai)
        RateLimiter::for('auth', function (Request $request): array {
            $email = mb_strtolower((string) $request->input('email'));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(10)->by('adresse-ip:'.$email.'|'.$ip),
                Limit::perMinute(30)->by('ip:'.$ip),
                Limit::perHour(300)->by('ip-heure:'.$ip),
            ];
        });

        // Toute l'API : 600 requêtes par minute et par compte, 300 par IP
        // sans compte (aspiration freinée même avec un jeton volé)
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user('sanctum');

            return $user
                ? Limit::perMinute(600)->by('compte:'.$user->getAuthIdentifier())
                : Limit::perMinute(300)->by('ip:'.$request->ip());
        });

        // Espace propriétaire : les gestes qui écrivent chez une clinique ou
        // engagent de l'argent, par compte
        $parCompte = fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
        RateLimiter::for('espace-demandes', fn (Request $request): array => [
            Limit::perHour(10)->by('demandes-h:'.$parCompte($request)),
            Limit::perDay(30)->by('demandes-j:'.$parCompte($request)),
        ]);
        RateLimiter::for('espace-reclamer', fn (Request $request): array => [
            Limit::perHour(5)->by('reclamer-h:'.$parCompte($request)),
            Limit::perDay(10)->by('reclamer-j:'.$parCompte($request)),
        ]);
        RateLimiter::for('espace-messages', fn (Request $request): Limit => Limit::perHour(30)->by('messages:'.$parCompte($request)));
        RateLimiter::for('espace-commandes', fn (Request $request): Limit => Limit::perHour(10)->by('commandes:'.$parCompte($request)));
        RateLimiter::for('espace-achats', fn (Request $request): Limit => Limit::perHour(30)->by('achats:'.$parCompte($request)));

        // Appels à Stripe depuis la caisse : la limite de Stripe est commune
        // à toute la plateforme, une clinique ne l'épuise pas (clinique du
        // jeton : le limiteur passe avant le contexte clinique)
        RateLimiter::for('stripe', function (Request $request): Limit {
            $jeton = $request->user()?->currentAccessToken();

            return Limit::perMinute(180)->by('stripe:'.($jeton instanceof JetonApi ? $jeton->clinique_id : $request->ip()));
        });

        // Fichiers publics (photos, logos) et autorisation de certificat, par IP
        RateLimiter::for('fichiers', fn (Request $request): Limit => Limit::perMinute(300)->by('fichiers:'.$request->ip()));

        // Envoi d'un code : par destinataire, toutes IP confondues (une
        // boîte n'est pas bombardée, un code ne part pas en boucle)
        RateLimiter::for('code-email', function (Request $request): array {
            $email = mb_strtolower((string) ($request->input('email') ?? $request->user()?->email));

            return [
                Limit::perMinutes(15, 3)->by('code:'.$email),
                Limit::perDay(10)->by('code-jour:'.$email),
            ];
        });
    }
}
