<?php

use App\Http\Controllers\Web\DesabonnementRappelsController;
use App\Http\Controllers\Web\VerificationCliniqueController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Vérification à la main d'une clinique inscrite (liens signés de l'e-mail
// envoyé à la plateforme) : la page confirme, le POST décide.
Route::prefix('verification-cliniques/{organisation}')->middleware(['signed', 'throttle:20,1'])->group(function (): void {
    Route::get('valider', [VerificationCliniqueController::class, 'confirmerValidation'])->name('verification-clinique.valider');
    Route::post('valider', [VerificationCliniqueController::class, 'valider']);
    Route::get('refuser', [VerificationCliniqueController::class, 'confirmerRefus'])->name('verification-clinique.refuser');
    Route::post('refuser', [VerificationCliniqueController::class, 'refuser']);
});

// « Ne plus recevoir les rappels » des e-mails aux propriétaires (lien signé,
// sans date : un e-mail se relit tard) ; le POST vient aussi de la messagerie
// (désabonnement en un clic), sans jeton CSRF.
Route::prefix('rappels/desabonner/{client}')->middleware(['signed', 'throttle:20,1'])->group(function (): void {
    Route::get('/', [DesabonnementRappelsController::class, 'confirmer'])->whereNumber('client')->name('rappels.desabonner');
    Route::post('/', [DesabonnementRappelsController::class, 'desabonner'])->whereNumber('client');
});
