<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('vehicles')
            ->whereIn('current_state', ['inside', 'INSIDE'])
            ->update(['current_state' => 'INSIDE']);

        DB::table('vehicles')
            ->where(function ($query): void {
                $query->whereNull('current_state')
                    ->orWhereNotIn('current_state', ['INSIDE']);
            })
            ->update(['current_state' => 'OUTSIDE']);

        DB::table('rfid_scan_logs')
            ->whereIn('resulting_state', ['inside', 'INSIDE'])
            ->update(['resulting_state' => 'INSIDE']);

        DB::table('rfid_scan_logs')
            ->whereIn('resulting_state', ['outside', 'OUTSIDE'])
            ->update(['resulting_state' => 'OUTSIDE']);

        DB::table('vehicle_events')
            ->whereIn('resulting_state', ['inside', 'INSIDE'])
            ->update(['resulting_state' => 'INSIDE']);

        DB::table('vehicle_events')
            ->whereIn('resulting_state', ['outside', 'OUTSIDE'])
            ->update(['resulting_state' => 'OUTSIDE']);

        if (Schema::hasColumn('vehicles', 'current_state')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->string('current_state', 20)->default('OUTSIDE')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('vehicles')
            ->whereIn('current_state', ['INSIDE', 'inside'])
            ->update(['current_state' => 'inside']);

        DB::table('vehicles')
            ->whereIn('current_state', ['OUTSIDE', 'outside'])
            ->update(['current_state' => 'outside']);

        DB::table('rfid_scan_logs')
            ->whereIn('resulting_state', ['INSIDE', 'inside'])
            ->update(['resulting_state' => 'inside']);

        DB::table('rfid_scan_logs')
            ->whereIn('resulting_state', ['OUTSIDE', 'outside'])
            ->update(['resulting_state' => 'outside']);

        DB::table('vehicle_events')
            ->whereIn('resulting_state', ['INSIDE', 'inside'])
            ->update(['resulting_state' => 'inside']);

        DB::table('vehicle_events')
            ->whereIn('resulting_state', ['OUTSIDE', 'outside'])
            ->update(['resulting_state' => 'outside']);

        if (Schema::hasColumn('vehicles', 'current_state')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->string('current_state', 20)->default('outside')->change();
            });
        }
    }
};
