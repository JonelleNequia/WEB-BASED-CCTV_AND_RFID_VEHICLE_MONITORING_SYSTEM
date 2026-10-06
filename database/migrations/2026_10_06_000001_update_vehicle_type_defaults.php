<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A4 (detection): the truck rule became "tall AND narrow" with new defaults
 * (35% / 1.05). A saved value that is still the old default (55% / 1.25,
 * saved only because the Cameras form sends every field) follows the new
 * default; a value someone chose is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->where('setting_key', 'perf_type_truck_min_height')->where('setting_value', '55')->update(['setting_value' => '35', 'updated_at' => now()]);
        DB::table('system_settings')->where('setting_key', 'perf_type_car_min_aspect')->where('setting_value', '1.25')->update(['setting_value' => '1.05', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('setting_key', 'perf_type_truck_min_height')->where('setting_value', '35')->update(['setting_value' => '55']);
        DB::table('system_settings')->where('setting_key', 'perf_type_car_min_aspect')->where('setting_value', '1.05')->update(['setting_value' => '1.25']);
    }
};
