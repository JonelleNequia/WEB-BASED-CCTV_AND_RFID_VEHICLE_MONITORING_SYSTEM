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
        if (Schema::hasColumn('active_sessions', 'time_in') && ! Schema::hasColumn('active_sessions', 'entry_time')) {
            Schema::table('active_sessions', function (Blueprint $table): void {
                $table->renameColumn('time_in', 'entry_time');
            });
        }

        if (! Schema::hasColumn('active_sessions', 'entry_time')) {
            Schema::table('active_sessions', function (Blueprint $table): void {
                $table->timestamp('entry_time')->nullable()->after('vehicle_color')->index();
            });
        }

        DB::table('active_sessions')
            ->where('status', 'active')
            ->update(['status' => 'open']);

        DB::table('active_sessions')
            ->where('status', 'completed')
            ->update(['status' => 'closed']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('active_sessions', 'entry_time') && ! Schema::hasColumn('active_sessions', 'time_in')) {
            Schema::table('active_sessions', function (Blueprint $table): void {
                $table->renameColumn('entry_time', 'time_in');
            });
        }
    }
};
