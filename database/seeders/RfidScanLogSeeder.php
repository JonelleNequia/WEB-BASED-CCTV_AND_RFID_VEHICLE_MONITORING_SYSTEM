<?php

namespace Database\Seeders;

use App\Services\RfidService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class RfidScanLogSeeder extends Seeder
{
    /**
     * Seed sample simulated RFID scans for offline demo and correlation testing.
     */
    public function run(): void
    {
        /** @var RfidService $rfidService */
        $rfidService = app(RfidService::class);

        $scans = [
            [
                'tag_uid' => 'RFID-ABC-1001',
                'scan_location' => 'entrance',
                'scan_direction' => 'entry',
                'reader_name' => 'Entrance RFID Reader (Simulated)',
                'scan_time' => Carbon::today()->setTime(8, 3, 0)->toIso8601String(),
                'notes' => 'Verified entrance scan for the registered faculty vehicle.',
            ],
            [
                'tag_uid' => 'RFID-ABC-1001',
                'scan_location' => 'exit',
                'scan_direction' => 'exit',
                'reader_name' => 'Exit RFID Reader (Simulated)',
                'scan_time' => Carbon::today()->setTime(10, 17, 0)->toIso8601String(),
                'notes' => 'Verified exit scan correlated to the matched EXIT event.',
            ],
            [
                'tag_uid' => 'RFID-GHI-1003',
                'scan_location' => 'entrance',
                'scan_direction' => 'entry',
                'reader_name' => 'Entrance RFID Reader (Simulated)',
                'scan_time' => Carbon::today()->setTime(11, 8, 0)->toIso8601String(),
                'notes' => 'Verified entrance scan for the service van.',
            ],
            [
                'tag_uid' => 'UNKNOWN-TAG-9001',
                'scan_location' => 'exit',
                'scan_direction' => 'exit',
                'reader_name' => 'Exit RFID Reader (Simulated)',
                'scan_time' => Carbon::today()->setTime(12, 46, 0)->toIso8601String(),
                'notes' => 'Unknown tag sample for manual verification testing.',
            ],
            [
                'tag_uid' => 'RFID-XYZ-1005',
                'scan_location' => 'exit',
                'scan_direction' => 'exit',
                'reader_name' => 'Exit RFID Reader (Simulated)',
                'scan_time' => Carbon::today()->setTime(12, 49, 0)->toIso8601String(),
                'notes' => 'Inactive tag sample for attention-state testing.',
            ],
        ];

        foreach ($scans as $scan) {
            $rfidService->simulate($scan);
        }
    }
}
