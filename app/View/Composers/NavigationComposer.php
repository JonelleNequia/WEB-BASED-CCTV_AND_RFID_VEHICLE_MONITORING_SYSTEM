<?php

namespace App\View\Composers;

use App\Models\Gate;
use App\Services\AlertSummaryService;
use App\Services\DetectorRuntimeService;
use App\Services\DeviceServiceRuntime;
use App\Services\SettingsService;
use Illuminate\View\View;
use Throwable;

/**
 * UI Phase 2: data for the sidebar (alert badge and system status dots).
 */
class NavigationComposer
{
    public function __construct(
        protected AlertSummaryService $alertSummaryService,
        protected DetectorRuntimeService $detectorRuntimeService,
        protected SettingsService $settingsService,
        protected DeviceServiceRuntime $deviceServiceRuntime
    ) {
    }

    public function compose(View $view): View
    {
        $isAdmin = auth()->user()?->isAdmin() === true;

        return $view->with([
            'navAlertCount' => $isAdmin ? $this->alertSummaryService->counts()['total'] : 0,
            'navHealth' => $this->health(),
        ]);
    }

    /**
     * UI Phase 4: three dots (Detector, Cameras, Readers); the detail is the
     * tooltip. Reader details (last tag, RSSI) are in Settings › Gates & Readers.
     *
     * @return list<array{key: string, label: string, ok: bool, detail: string}>
     */
    public function health(): array
    {
        try {
            $runtime = $this->detectorRuntimeService->readStatus();
        } catch (Throwable) {
            $runtime = [];
        }

        // Phase 1: one camera per gate.
        $gates = Gate::ordered();
        $cameraTotal = $gates->count();
        $cameras = collect($runtime['cameras'] ?? [])->only($gates->pluck('code')->all());
        $camerasOnline = $cameras->filter(fn ($camera): bool => (bool) ($camera['camera_running'] ?? false))->count();
        $detectorOnline = (bool) ($runtime['service_running'] ?? false);

        // Plug-and-detect: say why cameras are not live instead of "Standby".
        $cameraReason = null;
        if (! $detectorOnline) {
            $cameraReason = 'Detector off';
        } elseif ($camerasOnline < $cameraTotal) {
            $codes = $cameras->reject(fn ($camera): bool => (bool) ($camera['camera_running'] ?? false))
                ->pluck('error_code')->filter();
            $cameraReason = match ($codes->first()) {
                'unauthorized' => 'Login rejected',
                'not_found' => 'Wrong stream path',
                'unreachable', 'timeout' => 'Camera unreachable',
                'invalid_source' => 'Not set up',
                default => null,
            };
        }

        // Simulated gates use the Test Scan.
        // Plug-and-detect: a UHF reader is ready when the device service is
        // connected to it.
        try {
            $devices = $this->deviceServiceRuntime->readStatus();
        } catch (Throwable) {
            $devices = [];
        }

        $readerProblems = $gates
            ->filter(fn (Gate $gate): bool => $gate->reader_type === 'uhf_ethernet')
            ->map(function (Gate $gate) use ($devices): ?string {
                $link = (array) data_get($devices, "readers.{$gate->code}", []);

                return match (true) {
                    ! ($devices['service_running'] ?? false) => 'Device service off',
                    empty($link['target']) => 'Assign UHF reader',
                    ($link['state'] ?? null) !== 'connected' => 'UHF offline',
                    default => null,
                };
            })
            ->filter();
        $readersReady = $readerProblems->isEmpty();

        return [
            ['key' => 'detector', 'label' => 'Detector', 'ok' => $detectorOnline, 'detail' => $detectorOnline ? 'Running' : $this->detectorRuntimeService->notRunningReason()],
            ['key' => 'cameras', 'label' => 'Cameras', 'ok' => $camerasOnline === $cameraTotal, 'detail' => $camerasOnline.'/'.$cameraTotal.' live'.($cameraReason ? ' · '.$cameraReason : '')],
            ['key' => 'readers', 'label' => 'Readers', 'ok' => $readersReady, 'detail' => $readersReady ? 'Ready' : (string) $readerProblems->first()],
        ];
    }
}
