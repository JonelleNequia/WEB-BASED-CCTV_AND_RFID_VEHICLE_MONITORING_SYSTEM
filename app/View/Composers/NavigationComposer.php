<?php

namespace App\View\Composers;

use App\Services\AlertSummaryService;
use App\Services\DetectorRuntimeService;
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
        protected SettingsService $settingsService
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

        // NFC readers type into the Station page and the RFID Desk simulates;
        // a UHF Ethernet reader needs the network listener (not installed yet).
        $readerTypes = [
            $this->settingsService->get('entrance_reader_type', 'nfc'),
            $this->settingsService->get('exit_reader_type', 'nfc'),
        ];
        $readersReady = collect($readerTypes)->every(fn ($type): bool => in_array($type, ['nfc', 'simulated'], true));

        return [
            ['label' => 'Detector', 'ok' => $detectorOnline, 'detail' => $detectorOnline ? 'Running' : 'Standby'],
            ['label' => 'Cameras', 'ok' => $camerasOnline === 2, 'detail' => $camerasOnline.'/2 live'],
            ['label' => 'Readers', 'ok' => $readersReady, 'detail' => $readersReady ? 'Ready' : 'UHF listener pending'],
        ];
    }
}
