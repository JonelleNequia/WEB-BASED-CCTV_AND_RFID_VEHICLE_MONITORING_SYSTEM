<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6: the detector API key now comes from .env (DETECTOR_API_KEY).
 * Drop the old database copy, which held the public demo key.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->where('setting_key', 'python_api_key')->delete();
    }

    public function down(): void
    {
        // The demo key is not restored on purpose.
    }
};
