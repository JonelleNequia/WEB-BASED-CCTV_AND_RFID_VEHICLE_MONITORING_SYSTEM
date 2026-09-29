<?php

namespace App\View\Composers;

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
            'navUhf' => $this->uhfReaders(),
        ]);
    }

    /**
     * Assigned UHF readers: connected or not, last EPC, RSSI and time.
     *
     * @return list<array<string, mixed>>
     */
    protected function uhfReaders(): array
    {
        try {
            return $this->deviceServiceRuntime->uhfReaders();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    protected function health(): array
    {
        try {
            $runtime = $this->detectorRuntimeService->readStatus();
        } catch (Throwable) {
            $runtime = [];
        }

        $cameras = collect($runtime['cameras'] ?? [])->only(['entrance', 'exit']);
        $camerasOnline = $cameras->filter(fn ($camera): bool => (bool) ($camera['camera_running'] ?? false))->count();
        $detectorOnline = (bool) ($runtime['service_running'] ?? false);

        // Plug-and-detect: say why cameras are not live instead of "Standby".
        $cameraReason = null;
        if (! $detectorOnline) {
            $cameraReason = 'Detector off';
        } elseif ($camerasOnline < 2) {
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

        // NFC readers type into the Station page and the RFID Desk simulates.
        // Plug-and-detect: a UHF reader is ready when the device service is
        // connected to it.
        try {
            $devices = $this->deviceServiceRuntime->readStatus();
        } catch (Throwable) {
            $devices = [];
        }

        $readerProblems = collect(['entrance', 'exit'])
            ->filter(fn (string $station): bool => $this->settingsService->get("{$station}_reader_type", 'nfc') === 'uhf_ethernet')
            ->map(function (string $station) use ($devices): ?string {
                $link = (array) data_get($devices, "readers.$station", []);

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
            ['label' => 'Detector', 'ok' => $detectorOnline, 'detail' => $detectorOnline ? 'Running' : $this->detectorRuntimeService->notRunningReason()],
            ['label' => 'Cameras', 'ok' => $camerasOnline === 2, 'detail' => $camerasOnline.'/2 live'.($cameraReason ? ' · '.$cameraReason : '')],
            ['label' => 'Readers', 'ok' => $readersReady, 'detail' => $readersReady ? 'Ready' : (string) $readerProblems->first()],
        ];
    }
}
