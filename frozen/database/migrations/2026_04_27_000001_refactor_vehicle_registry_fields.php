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
        if (Schema::hasColumn('vehicles', 'owner_name') && ! Schema::hasColumn('vehicles', 'vehicle_owner_name')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->renameColumn('owner_name', 'vehicle_owner_name');
            });
        }

        if (! Schema::hasColumn('vehicles', 'rfid_tag_uid')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->string('rfid_tag_uid', 100)->nullable()->unique()->after('id');
            });
        }

        if (Schema::hasColumn('vehicles', 'vehicle_color')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->dropColumn('vehicle_color');
            });
        }

        if (Schema::hasColumn('vehicle_rfid_tags', 'tag_label')) {
            Schema::table('vehicle_rfid_tags', function (Blueprint $table): void {
                $table->dropColumn('tag_label');
            });
        }

        if (! Schema::hasColumn('rfid_scan_logs', 'guest_vehicle_observation_id')) {
            Schema::table('rfid_scan_logs', function (Blueprint $table): void {
                $table->foreignId('guest_vehicle_observation_id')
                    ->nullable()
                    ->after('correlated_vehicle_event_id')
                    ->constrained('guest_vehicle_observations')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('rfid_scan_logs', 'guest_vehicle_observation_id')) {
            Schema::table('rfid_scan_logs', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('guest_vehicle_observation_id');
            });
        }

        if (! Schema::hasColumn('vehicle_rfid_tags', 'tag_label')) {
            Schema::table('vehicle_rfid_tags', function (Blueprint $table): void {
                $table->string('tag_label')->nullable()->after('tag_uid');
            });
        }

        if (! Schema::hasColumn('vehicles', 'vehicle_color')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->string('vehicle_color')->nullable()->after('vehicle_type');
            });
        }

        if (Schema::hasColumn('vehicles', 'rfid_tag_uid')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->dropColumn('rfid_tag_uid');
            });
        }

        if (Schema::hasColumn('vehicles', 'vehicle_owner_name') && ! Schema::hasColumn('vehicles', 'owner_name')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->renameColumn('vehicle_owner_name', 'owner_name');
            });
        }
    }
};
