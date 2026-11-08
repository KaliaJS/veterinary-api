<?php

use App\Http\Controllers\Dev\FakeIsabvController;
use Illuminate\Support\Facades\Route;

/*
 * Faux serveur SI ABV de l'OSAV (08.11.2026), en local et dans les essais seulement
 * (bootstrap/app.php) : `services.isabv.url` y mène en local et dans les
 * essais. Mêmes chemins que le vrai serveur, sous /fake-isabv/api/isabv.
 */
Route::post('jwt/create', [FakeIsabvController::class, 'createJwt']);
Route::post('psw/prescription/deactivate/{prescriptionId}', [FakeIsabvController::class, 'deactivate']);
Route::post('psw/prescription/getDuplicatePrescriptions', [FakeIsabvController::class, 'duplicates']);
Route::post('psw/prescription/reactivateDuplicatePrescriptions', [FakeIsabvController::class, 'reactivate']);
Route::post('psw/prescription/{type}', [FakeIsabvController::class, 'createPrescription']);
Route::post('psw/protocol', [FakeIsabvController::class, 'protocolSearch']);
Route::post('psw/saveConsultation', [FakeIsabvController::class, 'saveConsultation']);
