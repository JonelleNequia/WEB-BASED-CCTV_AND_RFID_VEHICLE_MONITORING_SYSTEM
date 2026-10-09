<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The RFID check after a vehicle crosses the line ends within 2 seconds
 * (was 4): a tag read before the crossing (up to the lookback) matches at
 * once; without a tag the vehicle is recorded as a visitor after 2 s.
 * Only the old default changes; a value someone set by hand stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->where('setting_key', 'rfid_lookahead_seconds')->where('setting_value', '4')
            ->update(['setting_value' => '2', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('setting_key', 'rfid_lookahead_seconds')->where('setting_value', '2')
            ->update(['setting_value' => '4', 'updated_at' => now()]);
    }
};
