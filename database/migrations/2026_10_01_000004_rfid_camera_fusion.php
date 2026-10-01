<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (visitor model): RFID + camera fusion.
 *
 * - rfid_scan_logs: which crossing gave a registered scan its direction
 *   (fusion_status: pending / camera / toggle / scan_only, with a note), and
 *   the detector window that claimed the scan.
 * - vehicle_crossings: the registered scan linked to the crossing.
 * - Same-tag cooldown: at least 10 s. The old 0 (every read recorded, which
 *   made one tag create repeated guest records) becomes the 60 s default.
 * - Gates use UHF readers only: an NFC gate becomes a UHF gate.
 *
 * Existing scans keep their result (fusion_status stays empty for them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfid_scan_logs', function (Blueprint $table): void {
            $table->foreignId('vehicle_crossing_id')->nullable()->after('guest_vehicle_observation_id')
                ->constrained('vehicle_crossings')->nullOnDelete();
            $table->string('fusion_status', 20)->nullable()->after('outcome')->index();
            $table->string('fusion_note', 160)->nullable()->after('fusion_status');
            $table->string('detector_event_key', 120)->nullable()->after('fusion_note')->index();
        });

        Schema::table('vehicle_crossings', function (Blueprint $table): void {
            $table->foreignId('rfid_scan_log_id')->nullable()->after('camera_id')
                ->constrained('rfid_scan_logs')->nullOnDelete();
        });

        $cooldown = DB::table('system_settings')->where('setting_key', 'rfid_cooldown_seconds')->value('setting_value');
        if ($cooldown !== null && (int) $cooldown < 10) {
            DB::table('system_settings')->where('setting_key', 'rfid_cooldown_seconds')
                ->update(['setting_value' => (int) $cooldown === 0 ? '60' : '10', 'updated_at' => now()]);
        }

        foreach (DB::table('gates')->where('reader_type', 'nfc')->get(['id', 'name', 'reader_name']) as $gate) {
            DB::table('gates')->where('id', $gate->id)->update([
                'reader_type' => 'uhf_ethernet',
                'reader_name' => $gate->reader_name
                    ? (preg_replace('/\bNFC\b/i', 'UHF', $gate->reader_name) ?? $gate->reader_name)
                    : $gate->name.' UHF Reader',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('vehicle_crossings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rfid_scan_log_id');
        });

        Schema::table('rfid_scan_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_crossing_id');
            $table->dropIndex(['fusion_status']);
            $table->dropIndex(['detector_event_key']);
            $table->dropColumn(['fusion_status', 'fusion_note', 'detector_event_key']);
        });
    }
};
