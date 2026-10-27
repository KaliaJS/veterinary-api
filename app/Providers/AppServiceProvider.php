<?php

namespace App\Providers;

use App\Auth\ContexteClinique;
use App\Auth\ContexteProprietaire;
use App\Enums\DomainePermission;
use App\Models\JetonApi;
use App\Models\User;
use App\Paiements\PasserelleAbonnement;
use App\Paiements\PasserelleAbonnementStripe;
use App\Paiements\PasserelleIdentite;
use App\Paiements\PasserelleIdentiteStripe;
use App\Paiements\PasserellePaiement;
use App\Paiements\PasserelleStripe;
use App\Queue\EchecsMasques;
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
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
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

        // Les tâches en échec gardées sans les valeurs des messages SQL
        $this->app->extend('queue.failer', fn (FailedJobProviderInterface $fournisseur) => $fournisseur instanceof EchecsMasques ? $fournisseur : new EchecsMasques($fournisseur));

        // Paiements en caisse : Stripe (Terminal, TWINT), remplacé par un
        // double dans les tests
        $this->app->singleton(PasserellePaiement::class, fn () => new PasserelleStripe(
            new StripeClient(['api_key' => config('services.stripe.secret') ?: null]),
            config('services.stripe.webhook_secret'),
            config('services.stripe.webhook_secret_comptes'),
        ));

        // Abonnement SaaS des organisations : Stripe Billing, remplacé par
        // un double dans les tests. Une demande sans réponse est renvoyée
        // aussitôt par la bibliothèque, avec la même clé (recommandation de
        // Stripe, 26.10.2026)
        $this->app->singleton(PasserelleAbonnement::class, fn () => new PasserelleAbonnementStripe(
            new StripeClient(['api_key' => config('services.stripe.secret') ?: null, 'max_network_retries' => 2]),
        ));

        // Vérification d'identité des vétérinaires : Stripe Identity, sur
        // le compte de la plateforme (double dans les tests)
        $this->app->singleton(PasserelleIdentite::class, fn () => new PasserelleIdentiteStripe(
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
        // Un jeton sans fin ne pourrait jamais expirer : refusé (27.10.2026)
        Sanctum::authenticateAccessTokensUsing(fn (JetonApi $jeton, bool $valide): bool => $valide && $jeton->expires_at !== null);

        // Les adresses que l'API écrit (photos, logos, liens signés, e-mails)
        // partent d'APP_URL, jamais de l'en-tête Host de la requête (23.10.2026)
        if (filled(config('app.url'))) {
            URL::forceRootUrl((string) config('app.url'));
            if (str_starts_with((string) config('app.url'), 'https://')) {
                URL::forceScheme('https');
            }
        }

        // E-mails : une saisie (nom d'un animal, message) ne devient jamais
        // un lien ou du HTML (« [Payer](https://…) » reste du texte)
        Markdown::withSecuredEncoding();

        // Identifiants des routes : des chiffres seulement. « abc » répond
        // 404, jamais une erreur 500 dans un contrôleur qui attend un entier
        Route::patterns(array_fill_keys([
            'absence',
            'analyse',
            'animal',
            'client',
            'clinique',
            'cloture',
            'commande',
            'demande',
            'devis',
            'document',
            'employe',
            'enregistrement',
            'examen',
            'facture',
            'inscription',
            'intervention',
            'invitation',
            'ligne',
            'lot',
            'numero',
            'ordonnance',
            'organisation',
            'paiement',
            'passkey',
            'patient',
            'pesee',
            'prestation',
            'produit',
            'releve',
            'rendezVous',
            'role',
            'sejour',
            'session',
            'societe',
            'soin',
            'terminal',
            'urgence',
            'user',
            'vaccination',
            'veterinaire',
        ], '[0-9]+'));

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

        // Clé Vetpoint (27.10.2026) : 10 essais par minute et par compte, avec
        // un message en français. Les recherches, elles, sont comptées par
        // clinique dans RechercheVetpointController (vraies recherches seules)
        // Code de suppression du compte (27.10.2026) : par compte, jamais par un
        // champ de la requête qui changerait la clé
        RateLimiter::for('suppression-code', fn (Request $request): array => [
            Limit::perMinutes(15, 3)->by('suppression-code:'.$request->user()?->getAuthIdentifier())
                ->response(fn () => response()->json(['message' => 'Trop de codes demandés : réessayez dans un quart d’heure.'], 429)),
            Limit::perDay(10)->by('suppression-code-jour:'.$request->user()?->getAuthIdentifier())
                ->response(fn () => response()->json(['message' => 'Trop de codes demandés aujourd’hui : réessayez demain.'], 429)),
        ]);

        // Formulaire de contact du site (27.10.2026) : par adresse IP sans
        // compte, par compte une fois connecté (sans Turnstile alors)
        RateLimiter::for('contact', function (Request $request): array {
            $cle = ($compte = $request->user('sanctum')?->getAuthIdentifier()) !== null ? 'compte:'.$compte : 'ip:'.$request->ip();

            return [
                Limit::perHour(5)->by('contact:'.$cle)
                    ->response(fn () => response()->json(['message' => 'Trop de messages envoyés : réessayez dans une heure.'], 429)),
                Limit::perDay(20)->by('contact-jour:'.$cle)
                    ->response(fn () => response()->json(['message' => 'Trop de messages envoyés aujourd’hui : réessayez demain.'], 429)),
            ];
        });

        RateLimiter::for('vetpoint-cle', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('vetpoint-cle:'.$parCompte($request))
            ->response(fn () => response()->json(['message' => 'Trop d’essais de clé Vetpoint : réessayez dans une minute.'], 429)));

        // Enregistrements des consultations : chaque partie part chez
        // Deepgram, payée ; par compte (24.10.2026). Une partie arrive en
        // morceaux d'1 Mo au plus, et une synchronisation hors ligne en envoie
        // plusieurs d'un coup : 60 par minute.
        RateLimiter::for('transcription', fn (Request $request): Limit => Limit::perMinute(60)->by('transcription:'.$parCompte($request)));

        // Appels à Stripe depuis la caisse : la limite de Stripe est commune
        // à toute la plateforme, une clinique ne l'épuise pas (clinique du
        // jeton : le limiteur passe avant le contexte clinique)
        RateLimiter::for('stripe', function (Request $request): Limit {
            $jeton = $request->user()?->currentAccessToken();

            return Limit::perMinute(180)->by('stripe:'.($jeton instanceof JetonApi ? $jeton->clinique_id : $request->ip()));
        });

        // Abonnement de l'organisation (souscrire, carte, factures, packs) : son
        // propre compteur, par compte (26.10.2026). Une caisse chargée ne bloque
        // pas l'administrateur qui règle une facture, et inversement
        RateLimiter::for('stripe-abonnement', fn (Request $request): Limit => Limit::perMinute(30)->by('stripe-abonnement:'.$parCompte($request)));

        // Fichiers publics (photos, logos) et autorisation de certificat, par IP
        RateLimiter::for('fichiers', fn (Request $request): Limit => Limit::perMinute(300)->by('fichiers:'.$request->ip()));

        // Envoi d'un code (24.10.2026) : par destinataire et par IP, et plus
        // haut toutes IP confondues. Une boîte n'est pas bombardée, et
        // empêcher quelqu'un de recevoir son code demande beaucoup d'adresses
        // IP, plus seulement dix envois.
        RateLimiter::for('code-email', function (Request $request): array {
            $email = mb_strtolower((string) ($request->input('email') ?? $request->user()?->email));
            $ip = (string) $request->ip();

            return [
                Limit::perMinutes(15, 3)->by('code:'.$email.'|'.$ip),
                Limit::perDay(10)->by('code-jour:'.$email.'|'.$ip),
                Limit::perMinutes(15, 10)->by('code-adresse:'.$email),
                Limit::perDay(50)->by('code-adresse-jour:'.$email),
            ];
        });
    }
}
