<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0 (visitor model): the guest pass feature is removed.
 *
 * - Open guest visits are closed with the reason "guest pass removed"
 *   (no EXIT event: nobody saw the vehicle leave).
 * - Guest pass tags become ordinary vehicle tags. A UHF tag (EPC: 16+ hex
 *   characters in whole 16-bit words) becomes available; any other tag (an
 *   NFC card such as G-01) is disabled, because the gate reader is UHF.
 * - The guest pass settings are dropped.
 *
 * Nothing is deleted: visits, scans and events stay for history, and the old
 * pass label (G-01...) stays in display_number.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('guest_visits')
            ->whereIn('status', ['active', 'overstay'])
            ->orderBy('id')
            ->get(['id', 'notes'])
            ->each(function (object $visit) use ($now): void {
                DB::table('guest_visits')->where('id', $visit->id)->update([
                    'status' => 'completed',
                    'active_rfid_tag_id' => null,
                    'notes' => trim(($visit->notes ? $visit->notes."\n" : '').'Closed: guest pass removed.'),
                    'updated_at' => $now,
                ]);
            });

        DB::table('vehicle_rfid_tags')
            ->where('tag_type', 'guest_pass')
            ->orderBy('id')
            ->get(['id', 'uid', 'status'])
            ->each(function (object $tag) use ($now): void {
                $uid = strtoupper((string) $tag->uid);
                $isUhf = preg_match('/^[0-9A-F]{16,}$/', $uid) === 1 && strlen($uid) % 4 === 0;

                DB::table('vehicle_rfid_tags')->where('id', $tag->id)->update([
                    'tag_type' => 'vehicle',
                    'vehicle_id' => null,
                    'assigned_at' => null,
                    // A lost pass stays lost; otherwise UHF -> available, NFC -> disabled.
                    'status' => $tag->status === 'lost' ? 'lost' : ($isUhf ? 'available' : 'disabled'),
                    'updated_at' => $now,
                ]);
            });

        DB::table('system_settings')
            ->whereIn('setting_key', ['guest_pass_validity_minutes', 'guest_pass_overstay_grace_minutes', 'guest_pass_require_id'])
            ->delete();
    }

    public function down(): void
    {
        // One-way: the guest pass feature and its code are gone. Restore the
        // database backup made before Phase 0 to go back.
    }
};
