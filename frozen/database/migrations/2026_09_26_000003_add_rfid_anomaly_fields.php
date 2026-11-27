<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: flag RFID scans and events that need review.
 *
 * - is_anomaly / anomaly_reason: direction mismatch (e.g. ENTRY while already
 *   inside), a guest pass scanned at Exit without being issued, lost or
 *   disabled tags. Shown in "Needs Attention" and on the dashboard.
 * - rfid_scan_logs.guest_visit_id links a guest pass scan to its visit.
 * - rfid_scan_logs.outcome stores what the ingest decided (recorded, issue
 *   required, ignored, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfid_scan_logs', function (Blueprint $table): void {
            $table->boolean('is_anomaly')->default(false)->index();
            $table->string('anomaly_reason')->nullable();
            $table->string('outcome', 40)->nullable();
            $table->unsignedBigInteger('guest_visit_id')->nullable()->index();
        });

        Schema::table('vehicle_events', function (Blueprint $table): void {
            $table->string('anomaly_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_events', function (Blueprint $table): void {
            $table->dropColumn('anomaly_reason');
        });

        Schema::table('rfid_scan_logs', function (Blueprint $table): void {
            $table->dropIndex(['is_anomaly']);
            $table->dropIndex(['guest_visit_id']);
            $table->dropColumn(['is_anomaly', 'anomaly_reason', 'outcome', 'guest_visit_id']);
        });
    }
};
