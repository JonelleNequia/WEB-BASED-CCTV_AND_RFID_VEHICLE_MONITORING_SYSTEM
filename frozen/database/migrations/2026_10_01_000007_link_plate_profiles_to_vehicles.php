<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 (visitor model): a plate profile becomes a registered vehicle.
 *
 * "Register this vehicle" (Visitor Ranking) links the plate's profile and its
 * Unregistered Visitor records to the new Registry vehicle, so its history
 * moves with it. The records keep their category at the time of the visit
 * (they were unregistered then); the vehicle's ranking counts them.
 *
 * Existing profiles whose plate is already in the Registry are linked now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plate_profiles', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')->nullable()->after('visit_count')->constrained('vehicles')->nullOnDelete();
            $table->timestamp('registered_at')->nullable()->after('vehicle_id');
        });

        Schema::table('visitor_records', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')->nullable()->after('plate_profile_id')->constrained('vehicles')->nullOnDelete();
        });

        $vehicles = DB::table('vehicles')->get(['id', 'plate_number'])
            ->mapWithKeys(fn ($vehicle): array => [preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $vehicle->plate_number)) => $vehicle->id]);

        foreach (DB::table('plate_profiles')->whereNull('vehicle_id')->get(['id', 'plate_key']) as $profile) {
            if ($vehicleId = $vehicles[$profile->plate_key] ?? null) {
                DB::table('plate_profiles')->where('id', $profile->id)->update(['vehicle_id' => $vehicleId]);
                DB::table('visitor_records')->where('plate_profile_id', $profile->id)->update(['vehicle_id' => $vehicleId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('visitor_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_id');
        });

        Schema::table('plate_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropColumn('registered_at');
        });
    }
};
