<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTab;
use App\Http\Requests\SaveSettingsRequest;
use App\Models\DeviceAssignment;
use App\Models\Gate;
use App\Services\DetectorRuntimeService;
use App\Services\DeviceRegistryService;
use App\Services\DeviceServiceRuntime;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    use ResolvesTab;

    /**
     * B1 (Settings): three tabs for a non-technical user. Gates: a card per
     * gate (camera, RFID reader, detection zone). General: gate names.
     * Advanced: everything technical, for an admin or technician.
     */
    public const TABS = [
        'gates' => 'Gates',
        'general' => 'General',
        'advanced' => 'Advanced',
    ];

    /** B1: Advanced sections (each its own ?tab=, so older links keep working). */
    public const ADVANCED_SECTIONS = [
        'status' => 'System status',
        'devices' => 'All network devices',
        'detection' => 'Detection',
        'timing' => 'Timing',
        'manual' => 'Manual setup',
        'test-scan' => 'Test Scan',
    ];

    /** Older tab names (bookmarks, links in messages). */
    public const ALIASES = [
        'stations' => 'gates',
        'cameras' => 'manual',
        'advanced' => 'status',
    ];

    /** B3: settings a section can put back to their defaults. */
    public const RESTORABLE = [
        'timing' => [
            'rfid_cooldown_seconds', 'rfid_lookback_seconds', 'rfid_lookahead_seconds',
            'rfid_stationary_seconds', 'rfid_offline_fallback', 'rfid_offline_grace_seconds',
        ],
        'detection' => [
            'perf_stream_fps', 'perf_stream_width', 'perf_jpeg_quality', 'perf_detection_fps', 'perf_yolo_imgsz',
            'perf_yolo_device', 'perf_roi_crop', 'perf_hires_on_trigger',
            'perf_type_second_pass', 'perf_type_model', 'perf_type_truck_min_height', 'perf_type_car_min_aspect',
            'perf_cross_margin', 'perf_cross_min_points', 'perf_cross_min_move',
        ],
    ];

    /** The tab shown in the tab bar for a page (an Advanced section shows "Advanced"). */
    public static function mainTab(string $tab): string
    {
        return array_key_exists($tab, self::ADVANCED_SECTIONS) ? 'advanced' : ($tab === 'calibration' ? 'gates' : $tab);
    }

    /**
     * Show one Settings tab.
     */
    public function index(Request $request, SettingsService $settingsService): View
    {
        $requested = (string) $request->query('tab', 'gates');
        $tab = self::ALIASES[$requested] ?? $requested;

        if (! array_key_exists($tab, self::TABS) && ! array_key_exists($tab, self::ADVANCED_SECTIONS) && $tab !== 'calibration') {
            $tab = 'gates';
        }

        return match ($tab) {
            'calibration' => app()->call([app(CalibrationController::class), 'index']),
            'status' => app()->call([app(SystemStatusController::class), 'index']),
            'test-scan' => app()->call([app(RfidScanController::class), 'testScan']),
            default => $this->formTab($tab, $settingsService),
        };
    }

    /**
     * B3: put one Advanced section back to its default values.
     */
    public function restoreDefaults(Request $request, SettingsService $settingsService): RedirectResponse
    {
        $section = (string) $request->validate(['section' => ['required', 'in:'.implode(',', array_keys(self::RESTORABLE))]])['section'];
        $defaults = $settingsService->defaults();
        $settingsService->save(array_intersect_key($defaults, array_flip(self::RESTORABLE[$section])));
        app(DeviceRegistryService::class)->exportRuntimeConfig();

        return redirect()->route('settings.index', ['tab' => $section])
            ->with('status', self::ADVANCED_SECTIONS[$section].': default values restored.');
    }

    protected function formTab(string $tab, SettingsService $settingsService): View
    {
        $settingsService->ensureCameraRuntimeConfigExists();

        // Plug-and-detect: the device service lists cameras and readers (Gates, All network devices).
        if (in_array($tab, ['gates', 'devices'], true)) {
            app(DeviceServiceRuntime::class)->ensureRunning();
        }

        $gates = Gate::query()->orderBy('sort_order')->orderBy('id')->get();
        $devicesPayload = in_array($tab, ['gates', 'devices', 'manual'], true) ? app(DeviceRegistryService::class)->panelPayload() : null;
        $cameraLive = in_array($tab, ['gates', 'manual'], true)
            ? app(DetectorRuntimeService::class)->withViewerStreamUrls(app(DetectorRuntimeService::class)->ensureRunning(), request()->getHost())
            : null;

        return view('settings.index', [
            'tab' => $tab,
            // B1: one card per gate (camera, RFID reader, detection zone).
            'gateCards' => $tab === 'gates'
                ? app(\App\Services\GateSetupService::class)->cards($gates, $devicesPayload ?? [], app(\App\Services\CalibrationService::class)->cameraPayload(), $cameraLive ?? [])
                : [],
            'settings' => $settingsService->all(),
            'cameraConfigs' => $settingsService->cameraConfigurations(),
            'detectorKeySet' => $settingsService->detectorApiKey() !== '',
            'devicesPayload' => $devicesPayload,
            // Phase 1: every gate, active or not.
            'gates' => $gates,
            'cameraAssignments' => DeviceAssignment::query()->with('device')
                ->where('role', DeviceAssignment::ROLE_CAMERA)->get()->keyBy('station'),
            // Live previews (Gates, Manual setup) also count as viewers.
            'cameraLive' => $cameraLive,
        ]);
    }

    /**
     * Persist system settings from the admin form.
     */
    public function update(SaveSettingsRequest $request, SettingsService $settingsService): RedirectResponse
    {
        $settingsService->save($request->validated());

        // Live-latency work: stream roles of assigned cameras (Settings › Cameras).
        foreach ((array) $request->validated('camera_streams', []) as $station => $choice) {
            if (in_array($station, DeviceAssignment::stations(), true)) {
                app(DeviceRegistryService::class)->updateCameraStreams(
                    $station,
                    (string) ($choice['stream'] ?? 'sub'),
                    ($choice['snapshots'] ?? '0') === '1',
                );
            }
        }

        // Plug-and-detect: a manual reader address or label change goes to Python.
        app(DeviceRegistryService::class)->exportRuntimeConfig();

        $section = [
            'general' => 'General settings', 'timing' => 'Timing', 'detection' => 'Detection settings',
            'manual' => 'Manual setup', 'stations' => 'Gates & Readers', 'cameras' => 'Cameras',
        ][$request->input('section')] ?? 'Settings';

        return back()->with('status', $section.' saved.');
    }

    /**
     * Settings › System Status › "Reset activity data": the same as
     * `php artisan system:reset --activity`, with "RESET" typed to confirm
     * and a dated backup first.
     */
    public function resetActivity(Request $request, \App\Services\SystemResetService $resetService): RedirectResponse
    {
        $request->validate(
            ['confirm' => ['required', 'in:RESET']],
            ['confirm.required' => 'Type RESET to confirm.', 'confirm.in' => 'Type RESET (in capital letters) to confirm.']
        );

        try {
            $backup = $resetService->backup();
            $removed = collect($resetService->reset(\App\Services\SystemResetService::LEVEL_ACTIVITY));
        } catch (\Throwable $exception) {
            return redirect()->route('settings.index', ['tab' => 'status'])
                ->withErrors(['confirm' => 'Reset stopped, nothing more was removed: '.$exception->getMessage()]);
        }

        $rows = $removed->where('kind', 'rows')->where('action', 'delete')->sum('count');
        $files = $removed->where('kind', 'files')->where('action', 'delete')->sum('count');

        return redirect()->route('settings.index', ['tab' => 'status'])->with('status', sprintf(
            'Activity data reset: %d records and %d files removed; vehicles set to Outside. Backup: %s',
            $rows,
            $files,
            str_replace(base_path().DIRECTORY_SEPARATOR, '', $backup)
        ));
    }

    /**
     * Phase 1: add a gate (ready for Gate 2, 3... at deployment). It gets a
     * camera slot, a kiosk and a reader slot; assign devices in Devices.
     */
    public function storeGate(Request $request, SettingsService $settingsService): RedirectResponse
    {
        $validated = $request->validate(['name' => ['nullable', 'string', 'max:100']]);
        $code = Gate::nextCode();
        $number = (int) preg_replace('/\D+/', '', $code);
        $name = trim((string) ($validated['name'] ?? '')) ?: "Gate {$number}";

        Gate::query()->create([
            'code' => $code,
            'name' => $name,
            'sort_order' => ((int) Gate::query()->max('sort_order')) + 1,
            'is_active' => true,
            'reader_type' => 'uhf_ethernet',
            'reader_name' => $name.' UHF Reader',
        ]);

        $settingsService->ensureCameraRuntimeConfigExists();
        app(DeviceRegistryService::class)->exportRuntimeConfig();
        // The detector starts one camera worker per gate when it starts.
        app(DetectorRuntimeService::class)->ensureRunning(force: true);

        return redirect()->route('settings.index', ['tab' => 'gates'])
            ->with('status', "{$name} added. Add its camera and RFID reader, then set up its detection zone.");
    }
}
