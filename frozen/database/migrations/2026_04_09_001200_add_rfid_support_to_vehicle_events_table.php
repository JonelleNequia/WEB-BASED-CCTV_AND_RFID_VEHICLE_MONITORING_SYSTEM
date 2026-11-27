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
        Schema::table('vehicle_events', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')->nullable()->after('plate_confidence')->constrained()->nullOnDelete();
            $table->foreignId('rfid_scan_log_id')->nullable()->after('vehicle_id')->constrained('rfid_scan_logs')->nullOnDelete();
            $table->string('event_origin')->default('manual')->after('event_status')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropConstrainedForeignId('rfid_scan_log_id');
            $table->dropColumn('event_origin');
        });
    }
};
