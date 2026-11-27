<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rfid_scan_logs', function (Blueprint $table): void {
            $table->string('resolved_event_type', 20)->nullable()->after('scan_direction')->index();
            $table->string('resulting_state', 20)->nullable()->after('resolved_event_type')->index();
            $table->string('vehicle_category', 30)->nullable()->after('resulting_state')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfid_scan_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'resolved_event_type',
                'resulting_state',
                'vehicle_category',
            ]);
        });
    }
};

