<?php

use App\Http\Middleware\AppliquerContexteClinique;
use App\Http\Middleware\AppliquerContexteProprietaire;
use App\Http\Middleware\AutoriserDomainesSites;
use App\Http\Middleware\ExigerModerateur;
use App\Http\Middleware\ExigerOrganisationOuverte;
use App\Http\Middleware\ExigerServiceClinique;
use App\Http\Middleware\ForcerReponseJson;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('api', ForcerReponseJson::class);

        // Avant HandleCors : les domaines reliés aux sites sont des origines autorisées
        $middleware->prepend(AutoriserDomainesSites::class);

        $middleware->alias([
            'clinique' => AppliquerContexteClinique::class,
            'organisation.ouverte' => ExigerOrganisationOuverte::class,
            'proprietaire' => AppliquerContexteProprietaire::class,
            'service' => ExigerServiceClinique::class,
            'moderateur' => ExigerModerateur::class,
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
    })->create();
