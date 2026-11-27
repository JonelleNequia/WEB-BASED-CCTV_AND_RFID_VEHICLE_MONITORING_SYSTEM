<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Move the Exit camera's temporary local fallback from webcam 1 to webcam 0
     * only when it is still using the old default webcam source.
     */
    public function up(): void
    {
        DB::table('cameras')
            ->where('camera_role', 'exit')
            ->where('source_type', 'webcam')
            ->where('source_value', '1')
            ->update([
                'source_value' => '0',
                'last_connection_status' => 'unknown',
                'last_connection_message' => 'Exit camera temporarily uses local webcam 0 for testing.',
                'updated_at' => now(),
            ]);
    }

    /**
     * Restore the old fallback only for the same local webcam test setup.
     */
    public function down(): void
    {
        DB::table('cameras')
            ->where('camera_role', 'exit')
            ->where('source_type', 'webcam')
            ->where('source_value', '0')
            ->where('last_connection_message', 'Exit camera temporarily uses local webcam 0 for testing.')
            ->update([
                'source_value' => '1',
                'last_connection_status' => 'unknown',
                'last_connection_message' => 'Waiting for browser camera access.',
                'updated_at' => now(),
            ]);
    }
};
