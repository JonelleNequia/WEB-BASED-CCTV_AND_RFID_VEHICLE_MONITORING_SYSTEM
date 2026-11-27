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
        Schema::table('guest_vehicle_observations', function (Blueprint $table): void {
            if (! Schema::hasColumn('guest_vehicle_observations', 'plate_number')) {
                $table->string('plate_number', 50)->nullable()->after('plate_text')->index();
            }
        });

        DB::table('guest_vehicle_observations')
            ->whereNull('plate_number')
            ->whereNotNull('plate_text')
            ->update(['plate_number' => DB::raw('plate_text')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_vehicle_observations', function (Blueprint $table): void {
            if (Schema::hasColumn('guest_vehicle_observations', 'plate_number')) {
                $table->dropColumn('plate_number');
            }
        });
    }
};

