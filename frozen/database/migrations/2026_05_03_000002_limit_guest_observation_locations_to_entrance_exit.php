<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalize legacy guest observation locations to the two active stations.
     */
    public function up(): void
    {
        DB::table('guest_vehicle_observations')
            ->whereNotIn('location', ['entrance', 'exit'])
            ->update(['location' => 'entrance']);
    }

    /**
     * This migration intentionally does not restore deprecated locations.
     */
    public function down(): void
    {
        //
    }
};
