<?php

use App\Http\Middleware\AppliquerContexteClinique;
use App\Http\Middleware\AppliquerContexteProprietaire;
use App\Http\Middleware\AutoriserDomainesSites;
use App\Http\Middleware\ExigerFonctionEssai;
use App\Http\Middleware\ExigerModerateur;
use App\Http\Middleware\ExigerOrganisationOuverte;
use App\Http\Middleware\ExigerServiceClinique;
use App\Http\Middleware\ForcerReponseJson;
use App\Http\Middleware\ProxiesDeConfiance;
use App\Paiements\ErreurPaiement;
use App\Support\MasquageSql;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Temps réel (Reverb, 28.10.2026) : l'autorisation des canaux passe par
    // le jeton Sanctum et le contexte de clinique, sous /api/v1
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum', 'clinique', 'organisation.ouverte']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('api', ForcerReponseJson::class);

        // Désabonnement en un clic depuis la messagerie (lien signé, RFC 8058)
        $middleware->validateCsrfTokens(except: ['rappels/desabonner/*']);

        // L'adresse du client derrière le répartiteur de charge (TRUSTED_PROXIES)
        $middleware->replace(TrustProxies::class, ProxiesDeConfiance::class);

        // Limite générale de l'API (AppServiceProvider, limiteur `api`)
        $middleware->throttleApi();

        // Avant HandleCors : les domaines reliés aux sites sont des origines autorisées
        $middleware->prepend(AutoriserDomainesSites::class);

        $middleware->alias([
            'clinique' => AppliquerContexteClinique::class,
            'organisation.ouverte' => ExigerOrganisationOuverte::class,
            'proprietaire' => AppliquerContexteProprietaire::class,
            'service' => ExigerServiceClinique::class,
            'moderateur' => ExigerModerateur::class,
            'fonction' => ExigerFonctionEssai::class,
        ]);

        // Le contexte clinique doit être posé AVANT la résolution des
        // route model bindings : une ressource d'une autre clinique
        // doit être introuvable (404), pas résolue puis filtrée.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            AppliquerContexteClinique::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Un paramètre attendu seul, reçu en tableau (`?q[]=…`) : la requête
        // est mal formée (422), pas une panne du serveur (23.10.2026)
        $exceptions->render(fn (ErrorException $e, Request $request) => $request->is('api/*') && str_contains($e->getMessage(), 'Array to string conversion')
            ? response()->json(['message' => 'Un paramètre de la requête n’a pas la bonne forme.'], 422)
            : null);
        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof ErrorException && str_contains($e->getMessage(), 'Array to string conversion') && request()->is('api/*'));

        // Une erreur SQL va au journal sans la valeur que répète le message
        // du pilote MySQL, ni les arguments de la pile (24.10.2026)
        $exceptions->report(MasquageSql::rapporteur());

        // Limite d'un groupe de routes (`throttle:60,1`…) : le message de
        // Laravel est en anglais, l'app et la montre l'affichent (28.10.2026)
        // La durée dite est celle de la limite (Retry-After)
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $secondes = (int) ($e->getHeaders()['Retry-After'] ?? 60);
            $quand = match (true) {
                $secondes <= 60 => 'dans une minute',
                $secondes < 3600 => 'dans '.(int) ceil($secondes / 60).' minutes',
                default => 'plus tard',
            };

            return response()->json(['message' => 'Trop de requêtes : réessayez '.$quand.'.'], 429, $e->getHeaders());
        });

        // Refus du prestataire de paiement non rattrapé : un 422 avec son
        // message traduit, jamais un 500 (il reste noté au journal)
        $exceptions->render(fn (ErreurPaiement $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage(), 'errors' => ['paiement' => [$e->getMessage()]]], 422)
            : null);
    })->create();
