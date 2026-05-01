<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CalibrationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\GuestObservationController;
use App\Http\Controllers\IncompleteRecordController;
use App\Http\Controllers\ManualReviewController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RfidInventoryController;
use App\Http\Controllers\RfidScanController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\SystemStatusController;
use App\Http\Controllers\VehicleRegistryController;
use App\Http\Controllers\VehicleEventController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! Auth::check()) {
        return redirect()->route('login');
    }

    return redirect()->route('dashboard.index');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/station/entrance', [StationController::class, 'show'])
        ->defaults('location', 'entrance')
        ->name('stations.entrance');
    Route::get('/station/exit', [StationController::class, 'show'])
        ->defaults('location', 'exit')
        ->name('stations.exit');
    Route::get('/station/{location}/state', [StationController::class, 'state'])
        ->whereIn('location', ['entrance', 'exit'])
        ->name('stations.state');
    Route::post('/station/{location}/rfid-scan', [StationController::class, 'rfidScan'])
        ->whereIn('location', ['entrance', 'exit'])
        ->name('stations.rfid-scan');

    Route::get('/monitoring', fn () => redirect()->route('stations.entrance'))->name('monitoring.index');
    Route::get('/monitoring/live-state', [MonitoringController::class, 'liveState'])->name('monitoring.live-state');
    Route::get('/portals/{location}', function (string $location) {
        abort_unless(in_array($location, ['entrance', 'exit'], true), 404);

        return redirect()->route($location === 'exit' ? 'stations.exit' : 'stations.entrance');
    })->name('portals.show');

    Route::middleware('admin')->group(function (): void {
        Route::get('/admin', [DashboardController::class, 'index'])->name('dashboard.index');
        Route::redirect('/dashboard', '/admin')->name('dashboard.legacy');
        Route::get('/vehicle-registry', [VehicleRegistryController::class, 'index'])->name('vehicle-registry.index');
        Route::post('/vehicle-registry', [VehicleRegistryController::class, 'store'])->name('vehicle-registry.store');
        Route::get('/vehicle-registry/{vehicle}/edit', [VehicleRegistryController::class, 'edit'])->name('vehicle-registry.edit');
        Route::put('/vehicle-registry/{vehicle}', [VehicleRegistryController::class, 'update'])->name('vehicle-registry.update');
        Route::get('/rfid-inventory', [RfidInventoryController::class, 'index'])->name('rfid-inventory.index');
        Route::post('/rfid-inventory', [RfidInventoryController::class, 'store'])->name('rfid-inventory.store');
        Route::get('/rfid-scans', [RfidScanController::class, 'index'])->name('rfid-scans.index');
        Route::post('/rfid-scans/simulate', [RfidScanController::class, 'store'])->name('rfid-scans.store');
        Route::get('/guest-observations', [GuestObservationController::class, 'index'])->name('guest-observations.index');
        Route::post('/guest-observations', [GuestObservationController::class, 'store'])->name('guest-observations.store');
        Route::get('/vehicle-events', [VehicleEventController::class, 'index'])->name('vehicle-events.index');
        Route::get('/vehicle-events/create', [VehicleEventController::class, 'create'])->name('vehicle-events.create');
        Route::post('/vehicle-events', [VehicleEventController::class, 'store'])->name('vehicle-events.store');
        Route::get('/vehicle-events/export/csv', [VehicleEventController::class, 'exportCsv'])->name('vehicle-events.export.csv');
        Route::get('/vehicle-events/{vehicleEvent}', [VehicleEventController::class, 'show'])
            ->whereNumber('vehicleEvent')
            ->name('vehicle-events.show');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export/csv', [ReportController::class, 'exportCsv'])->name('reports.export.csv');
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::get('/camera-calibration', [CalibrationController::class, 'index'])->name('calibration.index');
        Route::put('/calibration', [CalibrationController::class, 'update'])->name('calibration.update');
        Route::put('/camera-browser/state', [CalibrationController::class, 'syncState'])->name('camera-browser.state');
        Route::get('/evidence/rfid-scans/{rfidScanLog}/payload', [EvidenceController::class, 'downloadRfidPayload'])
            ->name('evidence.rfid.payload');

        Route::put('/vehicle-events/{vehicleEvent}/complete', [VehicleEventController::class, 'complete'])
            ->whereNumber('vehicleEvent')
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
