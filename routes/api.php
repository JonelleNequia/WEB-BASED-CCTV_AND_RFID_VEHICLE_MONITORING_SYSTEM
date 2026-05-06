<?php

use App\Http\Controllers\Api\FutureIntegrationController;
use App\Http\Controllers\GuestObservationController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:1200,1')->group(function (): void {
    Route::get('/latest-scan', [FutureIntegrationController::class, 'rfidMatch'])
        ->name('api.latest-scan');
    Route::get('/check-latest-scan', [FutureIntegrationController::class, 'rfidMatch'])
        ->name('api.check-latest-scan');
    Route::post('/guest-observation', [GuestObservationController::class, 'store'])
        ->name('api.guest-observation');
});

Route::prefix('v1/integration')
    ->name('api.integration.')
    ->middleware('throttle:1200,1')
    ->group(function (): void {
        Route::get('/status', [FutureIntegrationController::class, 'status'])->name('status');
        Route::get('/rfid-match', [FutureIntegrationController::class, 'rfidMatch'])->name('rfid-match');
        Route::post('/events', [FutureIntegrationController::class, 'receive'])->name('events');
        Route::post('/guest-observations', [GuestObservationController::class, 'store'])->name('guest-observations');
        Route::post('/rfid-scans', [FutureIntegrationController::class, 'receiveRfidScan'])->name('rfid-scans');
    });
