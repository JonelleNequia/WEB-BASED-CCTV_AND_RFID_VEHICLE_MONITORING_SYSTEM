<?php

namespace App\Http\Controllers;

use App\Models\RfidScanLog;
use App\Services\LocalStorageService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceController extends Controller
{
    /**
     * Download one RFID payload export as evidence.
     */
    public function downloadRfidPayload(RfidScanLog $rfidScanLog, LocalStorageService $localStorageService): StreamedResponse
    {
        abort_if(blank($rfidScanLog->payload_file_path), 404);

        $disk = $localStorageService->archiveDisk();
        $path = (string) $rfidScanLog->payload_file_path;

        abort_unless(Storage::disk($disk)->exists($path), 404);

        $filename = sprintf(
            'rfid-evidence-%d-%s.json',
            $rfidScanLog->id,
            $rfidScanLog->scan_time?->format('Ymd-His') ?? now()->format('Ymd-His')
        );

        return Storage::disk($disk)->download($path, $filename);
    }
}
