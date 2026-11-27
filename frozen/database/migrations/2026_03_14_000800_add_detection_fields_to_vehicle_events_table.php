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
            $table->string('event_status')->default('completed')->after('match_status')->index();
            $table->string('detected_vehicle_type')->nullable()->after('vehicle_type');
            $table->string('external_event_key')->nullable()->after('camera_id')->unique();
            $table->json('detection_metadata_json')->nullable()->after('external_event_key');
            $table->timestamp('details_completed_at')->nullable()->after('detection_metadata_json');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_events', function (Blueprint $table): void {
            $table->dropColumn([
                'event_status',
                'detected_vehicle_type',
                'external_event_key',
                'detection_metadata_json',
                'details_completed_at',
            ]);
        });
    }
};
