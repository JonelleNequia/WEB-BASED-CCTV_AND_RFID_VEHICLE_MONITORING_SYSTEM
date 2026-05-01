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
        Schema::table('guest_vehicle_observations', function (Blueprint $table): void {
            if (! Schema::hasColumn('guest_vehicle_observations', 'status')) {
                $table->string('status', 30)->default('pending_review')->after('observation_source')->index();
            }

            if (! Schema::hasColumn('guest_vehicle_observations', 'external_event_key')) {
                $table->string('external_event_key', 120)->nullable()->after('camera_id')->unique();
            }

            if (! Schema::hasColumn('guest_vehicle_observations', 'detection_metadata_json')) {
                $table->json('detection_metadata_json')->nullable()->after('external_event_key');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_vehicle_observations', function (Blueprint $table): void {
            foreach (['detection_metadata_json', 'external_event_key', 'status'] as $column) {
                if (Schema::hasColumn('guest_vehicle_observations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
