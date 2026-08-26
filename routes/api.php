<?php

use App\Http\Controllers\Api\V1\Auth\CodeEmailController;
use App\Http\Controllers\Api\V1\Auth\EmailConnuController;
use App\Http\Controllers\Api\V1\Auth\PasskeyConnexionController;
use App\Http\Controllers\Api\V1\Auth\PasskeyController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\SalleController;
use Illuminate\Support\Facades\Route;

/*
 * API v1 — jetons Sanctum uniquement (jamais de session SPA).
 * Les routes authentifiées passent toutes par le middleware `clinique`
 * qui applique le scope multi-clinique du jeton, globalement.
 */
Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->middleware('throttle:auth')->group(function (): void {
        Route::post('email', EmailConnuController::class);
        Route::post('code-email/envoyer', [CodeEmailController::class, 'envoyer']);
        Route::post('code-email/verifier', [CodeEmailController::class, 'verifier']);
        Route::post('passkey/options', [PasskeyConnexionController::class, 'options']);
        Route::post('passkey/verifier', [PasskeyConnexionController::class, 'verifier']);
    });

    Route::middleware(['auth:sanctum', 'clinique'])->group(function (): void {
        Route::prefix('auth')->group(function (): void {
            Route::get('moi', [SessionController::class, 'moi']);
            Route::post('rafraichir', [SessionController::class, 'rafraichir']);
            Route::post('deconnexion', [SessionController::class, 'deconnexion']);

            Route::get('passkeys', [PasskeyController::class, 'index']);
            Route::post('passkeys/options', [PasskeyController::class, 'options']);
            Route::post('passkeys', [PasskeyController::class, 'enregistrer']);
            Route::delete('passkeys/{passkey}', [PasskeyController::class, 'supprimer']);
        });

        Route::apiResource('salles', SalleController::class)->except('destroy');

        // Domaine facturation — routes à venir, toutes derrière
        // l'ability dédiée (une ASV n'y a pas accès).
        Route::middleware('abilities:facturation')->prefix('facturation')->group(function (): void {
            //
        });
    });
});
