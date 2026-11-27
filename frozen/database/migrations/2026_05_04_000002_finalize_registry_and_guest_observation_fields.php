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
        if (Schema::hasColumn('vehicles', 'registry_snapshot')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->dropColumn('registry_snapshot');
            });
        }

        if (! Schema::hasColumn('guest_vehicle_observations', 'vehicle_color')) {
            Schema::table('guest_vehicle_observations', function (Blueprint $table): void {
                $table->string('vehicle_color', 50)->nullable()->after('vehicle_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('vehicles', 'registry_snapshot')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->string('registry_snapshot')->nullable()->after('vehicle_type');
            });
        }
    }
};
