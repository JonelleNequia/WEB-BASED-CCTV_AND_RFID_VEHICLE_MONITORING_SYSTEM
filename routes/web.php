<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CalibrationController;
use App\Http\Controllers\CameraFileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\GuestObservationController;
use App\Http\Controllers\GateMonitorController;
use App\Http\Controllers\GuestPassController;
use App\Http\Controllers\RegistryController;
use App\Http\Controllers\RfidScanController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\VehicleRegistryController;
use App\Http\Controllers\VehicleEventController;
use App\Support\CameraFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * UI Phase 2: old page URLs redirect to the new page + tab, keeping filters.
 */
$legacyRedirect = static fn (string $route, array $params = []) => static fn (Request $request) => redirect()->route(
    $route,
    $params + $request->query()
);

Route::get('/', function () {
    if (! Auth::check()) {
        return redirect()->route('login');
    }

    // UI Phase 2: guards (non-admin) start on the Gate Monitor.
    return redirect()->route(Auth::user()?->isAdmin() ? 'dashboard.index' : 'gates.index');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

// Phase 6: camera frames and detector status are no longer public files.
Route::middleware('auth')->group(function (): void {
    Route::get('/camera/{role}/frame/{kind?}', [CameraFileController::class, 'frame'])
        ->whereIn('role', CameraFiles::ROLES)
        ->whereIn('kind', CameraFiles::KINDS)
        ->name('camera.frame');
    Route::get('/camera/status', [CameraFileController::class, 'status'])
        ->middleware('admin')
        ->name('camera.status');
});

// Phase 1: 'detector' keeps vehicle detection running on every signed-in page.
Route::middleware(['auth', 'detector'])->group(function () use ($legacyRedirect): void {
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
    // Phase 3: issue an available guest pass (used by the Entrance Station pop-up in Phase 4).
    Route::post('/guest-passes/{rfidTag}/issue', [GuestPassController::class, 'issue'])
        ->whereNumber('rfidTag')
        ->name('guest-passes.issue');
    // Phase 4: Exit Station "Card returned" button.
    Route::post('/guest-passes/visits/{guestVisit}/card-returned', [GuestPassController::class, 'cardReturned'])
        ->whereNumber('guestVisit')
        ->name('guest-passes.visits.card-returned');

    // UI Phase 2: Gate Monitor shows Entrance and Exit side by side (all signed-in users).
    Route::get('/gates', [GateMonitorController::class, 'index'])->name('gates.index');
    Route::get('/gates/state', [GateMonitorController::class, 'state'])->name('gates.state');
    Route::get('/monitoring', fn () => redirect()->route('gates.index'))->name('monitoring.index');
    Route::get('/portals/{location}', fn () => redirect()->route('gates.index'))
        ->whereIn('location', ['entrance', 'exit'])
        ->name('portals.show');

    Route::middleware('admin')->group(function () use ($legacyRedirect): void {
        Route::get('/admin', [DashboardController::class, 'index'])->name('dashboard.index');
        Route::get('/admin/live-state', [DashboardController::class, 'liveState'])->name('dashboard.live-state');
        Route::redirect('/dashboard', '/admin')->name('dashboard.legacy');
        // UI Phase 2: the six sidebar pages.
        Route::get('/registry', [RegistryController::class, 'index'])->name('registry.index');
        Route::get('/guests', [GuestPassController::class, 'index'])->name('guests.index');
        Route::get('/logs', [ActivityLogController::class, 'index'])->name('logs.index');

        // UI Phase 2: old page URLs (names kept so old links keep working).
        Route::get('/vehicle-registry', $legacyRedirect('registry.index', ['tab' => 'vehicles']))->name('vehicle-registry.index');
        Route::get('/rfid-inventory', function (Request $request) {
            $tab = $request->query('tag_type') === 'guest_pass' ? 'passes' : 'tags';

            return redirect()->route('registry.index', ['tab' => $tab] + $request->except('tag_type'));
        })->name('rfid-inventory.index');
        Route::get('/rfid-scans', $legacyRedirect('logs.index', ['tab' => 'scans']))->name('rfid-scans.index');
        Route::get('/guest-observations', $legacyRedirect('logs.index', ['tab' => 'alerts']))->name('guest-observations.index');
        Route::get('/vehicle-events', $legacyRedirect('logs.index', ['tab' => 'events']))->name('vehicle-events.index');
        Route::get('/camera-calibration', $legacyRedirect('settings.index', ['tab' => 'calibration']))->name('calibration.index');
        Route::get('/system-status', $legacyRedirect('settings.index', ['tab' => 'status']))->name('system-status.index');
        Route::get('/guest-passes', $legacyRedirect('guests.index'))->name('guest-passes.index');

        // UI Phase 3: Registry actions (side panel, tag lookup, replace tag, status).
        Route::get('/registry/vehicles/{vehicle}', [VehicleRegistryController::class, 'show'])
            ->whereNumber('vehicle')
            ->name('registry.vehicles.show');
        Route::post('/registry/vehicles/{vehicle}/replace-tag', [VehicleRegistryController::class, 'replaceTag'])
            ->whereNumber('vehicle')
            ->name('registry.vehicles.replace-tag');
        Route::post('/registry/vehicles/{vehicle}/status', [VehicleRegistryController::class, 'updateStatus'])
            ->whereNumber('vehicle')
            ->name('registry.vehicles.status');
        Route::post('/registry/tags/lookup', [VehicleRegistryController::class, 'lookupTag'])->name('registry.tags.lookup');
        Route::post('/registry/tags/{rfidTag}/status', [VehicleRegistryController::class, 'updateTagStatus'])
            ->whereNumber('rfidTag')
            ->name('registry.tags.status');
        Route::post('/rfid-inventory', [VehicleRegistryController::class, 'storeRfidTag'])->name('rfid-inventory.store');
        Route::post('/vehicle-registry/rfid-tags', [VehicleRegistryController::class, 'storeRfidTag'])->name('vehicle-registry.rfid-tags.store');
        Route::post('/vehicle-registry', [VehicleRegistryController::class, 'store'])->name('vehicle-registry.store');
        Route::get('/vehicle-registry/{vehicle}/edit', [VehicleRegistryController::class, 'edit'])->name('vehicle-registry.edit');
        Route::put('/vehicle-registry/{vehicle}', [VehicleRegistryController::class, 'update'])->name('vehicle-registry.update');
        Route::post('/rfid-scans/simulate', [RfidScanController::class, 'store'])->name('rfid-scans.store');
        // Phase 4: guest visit page and actions.
        Route::get('/guest-passes/visits/{guestVisit}', [GuestPassController::class, 'show'])
            ->whereNumber('guestVisit')
            ->name('guest-passes.visits.show');
        Route::post('/guest-passes/visits/{guestVisit}/close', [GuestPassController::class, 'close'])
            ->whereNumber('guestVisit')
            ->name('guest-passes.visits.close');
        Route::post('/guest-passes/visits/{guestVisit}/lost', [GuestPassController::class, 'markLost'])
            ->whereNumber('guestVisit')
            ->name('guest-passes.visits.lost');
        Route::post('/guest-observations', [GuestObservationController::class, 'store'])->name('guest-observations.store');
        Route::patch('/guest-observations/{guestVehicleObservation}', [GuestObservationController::class, 'update'])
            ->whereNumber('guestVehicleObservation')
            ->name('guest-observations.update');
        Route::patch('/guest-observations/{guestVehicleObservation}/verify', [GuestObservationController::class, 'verify'])
            ->whereNumber('guestVehicleObservation')
            ->name('guest-observations.verify');
        Route::get('/vehicle-events/create', [VehicleEventController::class, 'create'])->name('vehicle-events.create');
        Route::post('/vehicle-events', [VehicleEventController::class, 'store'])->name('vehicle-events.store');
        Route::get('/vehicle-events/export/csv', [VehicleEventController::class, 'exportCsv'])->name('vehicle-events.export.csv');
        Route::get('/vehicle-events/{vehicleEvent}', [VehicleEventController::class, 'show'])
            ->whereNumber('vehicleEvent')
            ->name('vehicle-events.show');
        Route::redirect('/reports', '/logs')->name('reports.index');
        Route::get('/reports/export/csv', fn () => redirect()->route('vehicle-events.export.csv', request()->query()))
            ->name('reports.export.csv');
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        // Plug-and-detect: Settings › Stations & Readers › Devices.
        Route::get('/settings/devices', [DeviceController::class, 'index'])->name('settings.devices.index');
        Route::post('/settings/devices/scan', [DeviceController::class, 'scan'])->name('settings.devices.scan');
        Route::post('/settings/devices/identify', [DeviceController::class, 'identify'])->name('settings.devices.identify');
        Route::post('/settings/devices/unassign', [DeviceController::class, 'unassign'])->name('settings.devices.unassign');
        Route::post('/settings/devices/acknowledge', [DeviceController::class, 'acknowledge'])->name('settings.devices.acknowledge');
        Route::post('/settings/devices/{networkDevice}/assign', [DeviceController::class, 'assign'])
            ->whereNumber('networkDevice')
            ->name('settings.devices.assign');
        Route::get('/camera-calibration/heartbeat', [CalibrationController::class, 'heartbeat'])->name('calibration.heartbeat');
        Route::put('/calibration', [CalibrationController::class, 'update'])->name('calibration.update');
        Route::put('/camera-browser/state', [CalibrationController::class, 'syncState'])->name('camera-browser.state');
        Route::get('/evidence/rfid-scans/{rfidScanLog}/payload', [EvidenceController::class, 'downloadRfidPayload'])
            ->name('evidence.rfid.payload');

        Route::put('/vehicle-events/{vehicleEvent}/complete', [VehicleEventController::class, 'complete'])
            ->whereNumber('vehicleEvent')
            ->name('vehicle-events.complete');

        Route::redirect('/manual-review', '/logs')->name('manual-review.index');

        Route::get('/incomplete-records', $legacyRedirect('logs.index', ['tab' => 'alerts']))->name('incomplete-records.index');
    });
});
