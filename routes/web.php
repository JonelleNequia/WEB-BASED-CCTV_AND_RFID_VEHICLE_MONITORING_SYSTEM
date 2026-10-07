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
use App\Http\Controllers\RegistryController;
use App\Http\Controllers\RfidScanController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\VehicleRegistryController;
use App\Http\Controllers\VisitorController;
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
        ->where('role', '[a-z0-9-]+')
        ->whereIn('kind', CameraFiles::KINDS)
        ->name('camera.frame');
    // Live view work: WebRTC / HLS through go2rtc, signed-in only.
    Route::post('/live/{gate}/webrtc', [\App\Http\Controllers\LiveViewController::class, 'webrtc'])->where('gate', '[a-z0-9-]+')->name('live.webrtc');
    Route::post('/live/{gate}/stats', [\App\Http\Controllers\LiveViewController::class, 'stats'])->where('gate', '[a-z0-9-]+')->name('live.stats');
    Route::get('/live/hls/{file}', [\App\Http\Controllers\LiveViewController::class, 'hls'])->where('file', '[a-z0-9./]+')->name('live.hls');
    Route::get('/camera/status', [CameraFileController::class, 'status'])
        ->middleware('admin')
        ->name('camera.status');
});

// Phase 1: 'detector' keeps vehicle detection running on every signed-in page.
Route::middleware(['auth', 'detector'])->group(function () use ($legacyRedirect): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Phase 1: one kiosk per gate. The old station links open Gate 1 / Gate 2.
    Route::get('/gates/{location}/kiosk', [StationController::class, 'show'])
        ->where('location', '[a-z0-9-]+')
        ->name('gates.kiosk');
    Route::get('/station/entrance', fn () => redirect()->route('gates.kiosk', 'gate-1'))->name('stations.entrance');
    Route::get('/station/exit', fn () => redirect()->route('gates.kiosk', 'gate-2'))->name('stations.exit');
    // {location}: a gate code, or the old "entrance" / "exit".
    Route::get('/station/{location}/state', [StationController::class, 'state'])
        ->where('location', '[a-z0-9-]+')
        ->name('stations.state');
    Route::post('/station/{location}/rfid-scan', [StationController::class, 'rfidScan'])
        ->where('location', '[a-z0-9-]+')
        ->name('stations.rfid-scan');

    // UI Phase 2: Gate Monitor shows every gate side by side (all signed-in users).
    Route::get('/devices/uhf-status', [DeviceController::class, 'uhfStatus'])->name('devices.uhf-status');
    Route::get('/system/health', \App\Http\Controllers\SystemHealthController::class)->name('system.health');
    Route::get('/gates', [GateMonitorController::class, 'index'])->name('gates.index');
    Route::get('/gates/state', [GateMonitorController::class, 'state'])->name('gates.state');
    Route::get('/monitoring', fn () => redirect()->route('gates.index'))->name('monitoring.index');
    Route::get('/portals/{location}', fn () => redirect()->route('gates.index'))
        ->where('location', '[a-z0-9-]+')
        ->name('portals.show');

    // Phase 5 (visitor model): Unregistered Visitor records and plate profiles.
    // Guards correct plates and keep notes; merging plates is for admins.
    Route::get('/visitors', [VisitorController::class, 'index'])->name('visitors.index');
    // Phase 8: the old Guests page is the Visitors page (guards too).
    Route::get('/guests', $legacyRedirect('visitors.index'))->name('guests.index');
    Route::post('/visitors/records', [VisitorController::class, 'store'])->name('visitors.records.store');
    Route::get('/visitors/plates/{plateProfile}', [VisitorController::class, 'showProfile'])->name('visitors.profiles.show');
    Route::patch('/visitors/records/{visitorRecord}/plate', [VisitorController::class, 'correctPlate'])->name('visitors.records.plate');
    Route::patch('/visitors/records/{visitorRecord}/dismiss', [VisitorController::class, 'dismiss'])->name('visitors.records.dismiss');
    Route::patch('/visitors/records/{visitorRecord}/type', [VisitorController::class, 'correctType'])->name('visitors.records.type');
    Route::patch('/visitors/plates/{plateProfile}/note', [VisitorController::class, 'updateNote'])->name('visitors.profiles.note');
    Route::post('/visitors/plates/{plateProfile}/merge', [VisitorController::class, 'merge'])
        ->middleware('admin')
        ->name('visitors.profiles.merge');

    Route::middleware('admin')->group(function () use ($legacyRedirect): void {
        Route::get('/admin', [DashboardController::class, 'index'])->name('dashboard.index');
        Route::get('/admin/live-state', [DashboardController::class, 'liveState'])->name('dashboard.live-state');
        Route::redirect('/dashboard', '/admin')->name('dashboard.legacy');
        // UI Phase 2: the six sidebar pages.
        Route::get('/registry', [RegistryController::class, 'index'])->name('registry.index');
        Route::get('/logs', [ActivityLogController::class, 'index'])->name('logs.index');

        // UI Phase 2: old page URLs (names kept so old links keep working).
        Route::get('/vehicle-registry', $legacyRedirect('registry.index', ['tab' => 'vehicles']))->name('vehicle-registry.index');
        Route::get('/rfid-inventory', function (Request $request) {
            return redirect()->route('registry.index', ['tab' => 'tags'] + $request->except('tag_type'));
        })->name('rfid-inventory.index');
        Route::get('/rfid-scans', $legacyRedirect('logs.index', ['tab' => 'scans']))->name('rfid-scans.index');
        Route::get('/guest-observations', $legacyRedirect('logs.index', ['tab' => 'alerts']))->name('guest-observations.index');
        Route::get('/vehicle-events', $legacyRedirect('logs.index', ['tab' => 'events']))->name('vehicle-events.index');
        Route::get('/camera-calibration', $legacyRedirect('settings.index', ['tab' => 'calibration']))->name('calibration.index');
        Route::get('/system-status', $legacyRedirect('settings.index', ['tab' => 'status']))->name('system-status.index');
        Route::get('/guest-passes', $legacyRedirect('logs.index', ['tab' => 'alerts']))->name('guest-passes.index');

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
        Route::get('/registry/tags/uhf-reads', [DeviceController::class, 'uhfReads'])->name('registry.tags.uhf-reads');
        Route::post('/registry/tags/{rfidTag}/status', [VehicleRegistryController::class, 'updateTagStatus'])
            ->whereNumber('rfidTag')
            ->name('registry.tags.status');
        Route::post('/rfid-inventory', [VehicleRegistryController::class, 'storeRfidTag'])->name('rfid-inventory.store');
        Route::post('/vehicle-registry/rfid-tags', [VehicleRegistryController::class, 'storeRfidTag'])->name('vehicle-registry.rfid-tags.store');
        Route::post('/vehicle-registry', [VehicleRegistryController::class, 'store'])->name('vehicle-registry.store');
        Route::get('/vehicle-registry/{vehicle}/edit', [VehicleRegistryController::class, 'edit'])->name('vehicle-registry.edit');
        Route::put('/vehicle-registry/{vehicle}', [VehicleRegistryController::class, 'update'])->name('vehicle-registry.update');
        Route::post('/rfid-scans/simulate', [RfidScanController::class, 'store'])->name('rfid-scans.store');
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
        Route::post('/settings/gates', [SettingsController::class, 'storeGate'])->name('settings.gates.store');
        // B1 (Settings): defaults and the gate cards' "⋯" actions.
        Route::post('/settings/restore-defaults', [SettingsController::class, 'restoreDefaults'])->name('settings.restore-defaults');
        Route::prefix('/settings/gates/{gate}')->where(['gate' => '[a-z0-9-]+'])->name('settings.gate.')->group(function (): void {
            Route::patch('/camera/name', [\App\Http\Controllers\GateSetupController::class, 'renameCamera'])->name('camera.name');
            Route::patch('/camera/login', [\App\Http\Controllers\GateSetupController::class, 'cameraLogin'])->name('camera.login');
            Route::post('/camera/test', [\App\Http\Controllers\GateSetupController::class, 'testCamera'])->name('camera.test');
            Route::delete('/camera', [\App\Http\Controllers\GateSetupController::class, 'removeCamera'])->name('camera.remove');
            Route::patch('/reader/name', [\App\Http\Controllers\GateSetupController::class, 'renameReader'])->name('reader.name');
            Route::delete('/reader', [\App\Http\Controllers\GateSetupController::class, 'removeReader'])->name('reader.remove');
            // Phase 2: move the reader into this PC's network (read, then apply after confirming).
            Route::get('/reader/network', [\App\Http\Controllers\ReaderNetworkController::class, 'status'])->name('reader.network');
            Route::post('/reader/network/read', [\App\Http\Controllers\ReaderNetworkController::class, 'read'])->name('reader.network.read');
            Route::post('/reader/network/apply', [\App\Http\Controllers\ReaderNetworkController::class, 'apply'])->name('reader.network.apply');
        });
        Route::post('/settings/reader-workaround', [\App\Http\Controllers\ReaderNetworkController::class, 'workaround'])->name('settings.reader-workaround');
        // Fresh start: activity data only, after a backup (same as `system:reset`).
        Route::post('/settings/system/reset-activity', [SettingsController::class, 'resetActivity'])->name('settings.system.reset-activity');
        // Plug-and-detect: Settings › Stations & Readers › Devices.
        Route::get('/settings/devices', [DeviceController::class, 'index'])->name('settings.devices.index');
        Route::post('/settings/devices/scan', [DeviceController::class, 'scan'])->name('settings.devices.scan');
        Route::post('/settings/devices/identify', [DeviceController::class, 'identify'])->name('settings.devices.identify');
        Route::post('/settings/devices/find', [DeviceController::class, 'find'])->name('settings.devices.find');
        Route::get('/settings/cameras/{station}/encoder', [DeviceController::class, 'encoderPreview'])
            ->where('station', '[a-z0-9-]+')->name('settings.cameras.encoder');
        Route::post('/settings/cameras/{station}/encoder/optimize', [DeviceController::class, 'encoderOptimize'])
            ->where('station', '[a-z0-9-]+')->name('settings.cameras.encoder.optimize');
        Route::get('/settings/status/metrics', [\App\Http\Controllers\SystemStatusController::class, 'metrics'])->name('settings.status.metrics');
        Route::post('/settings/devices/unassign', [DeviceController::class, 'unassign'])->name('settings.devices.unassign');
        Route::post('/settings/devices/acknowledge', [DeviceController::class, 'acknowledge'])->name('settings.devices.acknowledge');
        Route::post('/settings/devices/{networkDevice}/assign', [DeviceController::class, 'assign'])
            ->whereNumber('networkDevice')
            ->name('settings.devices.assign');
        Route::get('/camera-calibration/heartbeat', [CalibrationController::class, 'heartbeat'])->name('calibration.heartbeat');
        Route::put('/calibration', [CalibrationController::class, 'update'])->name('calibration.update');
        Route::post('/calibration/debug', [CalibrationController::class, 'debugOverlay'])->name('calibration.debug');
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
