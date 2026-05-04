<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('rfid_scan_logs')
            ->where('verification_status', 'unknown_tag')
            ->update([
                'verification_status' => 'guest',
                'updated_at' => now(),
            ]);

        DB::table('rfid_scan_logs')
            ->where('tag_uid', 'like', 'UNKNOWN-TAG%')
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('rfid_scan_logs')
                        ->where('id', $row->id)
                        ->update([
                            'tag_uid' => 'GUEST-RFID-'.$row->id,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('rfid_scan_logs')
            ->where('verification_status', 'guest')
            ->whereNull('vehicle_id')
            ->update([
                'verification_status' => 'unknown_tag',
                'updated_at' => now(),
            ]);
    }
};
