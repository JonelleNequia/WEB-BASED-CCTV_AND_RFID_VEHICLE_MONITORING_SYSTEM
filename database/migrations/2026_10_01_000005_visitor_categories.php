<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (visitor model): categories Faculty & Staff, Registered Visitor,
 * Unregistered Visitor.
 *
 * Registry vehicles in Parent, Student, Guard, Guest or a custom ("Others")
 * category become Registered Visitors (they have an RFID tag). Their old
 * category is kept in legacy_vehicle_categories, so down() restores it.
 * Scan logs and vehicle logs of Parent/Student/Guard get the new value too;
 * other history keeps its stored value (shown with the new names).
 */
return new class extends Migration
{
    protected const KEEP = ['faculty_staff', 'registered_visitor'];

    protected const LEGACY_REGISTERED = ['parent', 'student', 'guard'];

    public function up(): void
    {
        Schema::create('legacy_vehicle_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('category', 50)->nullable();
            $table->timestamp('migrated_at')->nullable();
        });

        $now = now();
        $vehicles = DB::table('vehicles')
            ->where(fn ($query) => $query->whereNull('category')->orWhereNotIn('category', self::KEEP))
            ->get(['id', 'category']);

        foreach ($vehicles as $vehicle) {
            DB::table('legacy_vehicle_categories')->insert([
                'vehicle_id' => $vehicle->id,
                'category' => $vehicle->category,
                'migrated_at' => $now,
            ]);
            DB::table('vehicles')->where('id', $vehicle->id)->update(['category' => 'registered_visitor', 'updated_at' => $now]);
        }

        foreach (['rfid_scan_logs', 'vehicle_events'] as $table) {
            DB::table($table)->whereIn('vehicle_category', self::LEGACY_REGISTERED)->update(['vehicle_category' => 'registered_visitor']);
        }
    }

    public function down(): void
    {
        foreach (DB::table('legacy_vehicle_categories')->get() as $row) {
            DB::table('vehicles')->where('id', $row->vehicle_id)->update(['category' => $row->category]);
        }

        Schema::dropIfExists('legacy_vehicle_categories');
    }
};
