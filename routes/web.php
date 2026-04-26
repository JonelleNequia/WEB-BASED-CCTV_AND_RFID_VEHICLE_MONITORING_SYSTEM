<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CalibrationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\GuestObservationController;
use App\Http\Controllers\IncompleteRecordController;
use App\Http\Controllers\ManualReviewController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RfidScanController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SystemStatusController;
use App\Http\Controllers\VehicleRegistryController;
use App\Http\Controllers\VehicleEventController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('dashboard.index')
        : redirect()->route('login');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/vehicle-registry', [VehicleRegistryController::class, 'index'])->name('vehicle-registry.index');
    Route::post('/vehicle-registry', [VehicleRegistryController::class, 'store'])->name('vehicle-registry.store');
    Route::get('/vehicle-registry/{vehicle}/edit', [VehicleRegistryController::class, 'edit'])->name('vehicle-registry.edit');
    Route::put('/vehicle-registry/{vehicle}', [VehicleRegistryController::class, 'update'])->name('vehicle-registry.update');
    Route::get('/rfid-scans', [RfidScanController::class, 'index'])->name('rfid-scans.index');
    Route::post('/rfid-scans/simulate', [RfidScanController::class, 'store'])->name('rfid-scans.store');

    Route::get('/monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
    Route::put('/camera-browser/state', [CalibrationController::class, 'syncState'])->name('camera-browser.state');

    Route::get('/guest-observations', [GuestObservationController::class, 'index'])->name('guest-observations.index');
    Route::post('/guest-observations', [GuestObservationController::class, 'store'])->name('guest-observations.store');

    Route::get('/vehicle-events', [VehicleEventController::class, 'index'])->name('vehicle-events.index');
    Route::get('/vehicle-events/export/csv', [VehicleEventController::class, 'exportCsv'])->name('vehicle-events.export.csv');
    Route::get('/vehicle-events/create', [VehicleEventController::class, 'create'])->name('vehicle-events.create');
    Route::post('/vehicle-events', [VehicleEventController::class, 'store'])->name('vehicle-events.store');
    Route::get('/vehicle-events/{vehicleEvent}', [VehicleEventController::class, 'show'])->name('vehicle-events.show');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export/csv', [ReportController::class, 'exportCsv'])->name('reports.export.csv');

    Route::get('/portals/{location}', [PortalController::class, 'show'])->name('portals.show');

    Route::get('/evidence/rfid-scans/{rfidScanLog}/payload', [EvidenceController::class, 'downloadRfidPayload'])
        ->name('evidence.rfid.payload');

    Route::middleware('admin')->group(function (): void {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::put('/calibration', [CalibrationController::class, 'update'])->name('calibration.update');

        Route::put('/vehicle-events/{vehicleEvent}/complete', [VehicleEventController::class, 'complete'])
            ->name('vehicle-events.complete');

        Route::get('/manual-review', [ManualReviewController::class, 'index'])->name('manual-review.index');
        Route::patch('/manual-review/{vehicleEvent}/mark-matched', [ManualReviewController::class, 'markMatched'])
            ->name('manual-review.mark-matched');
        Route::patch('/manual-review/{vehicleEvent}/mark-unmatched', [ManualReviewController::class, 'markUnmatched'])
            ->name('manual-review.mark-unmatched');

        Route::get('/incomplete-records', [IncompleteRecordController::class, 'index'])->name('incomplete-records.index');
        Route::get('/system-status', [SystemStatusController::class, 'index'])->name('system-status.index');
    });
});
