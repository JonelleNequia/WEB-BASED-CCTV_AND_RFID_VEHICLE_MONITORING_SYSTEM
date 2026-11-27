<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (visitor model): Unregistered Visitor records and plate profiles.
 *
 * - visitor_records: one vehicle the camera saw cross a gate with no
 *   registered tag read (a no-pass crossing): gate, direction, time,
 *   full-resolution snapshot, plate crop, and the plate (or "unreadable").
 * - plate_profiles: everything known about one plate (visits, first/last
 *   seen, vehicle, a visitor note). A misread plate is merged into the right
 *   one (merged_into_id); later reads of the misread plate follow it.
 *
 * No existing data changes; old CCTV guest observations are converted in
 * Phase 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plate_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('plate_key', 20)->unique();      // "ABC1234": letters and digits only
            $table->string('plate_number', 30);             // "ABC 1234": shown
            $table->string('vehicle_type', 50)->nullable();
            $table->string('vehicle_color', 30)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('note_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->unsignedInteger('visit_count')->default(0);
            $table->foreignId('merged_into_id')->nullable()->constrained('plate_profiles')->nullOnDelete();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at')->nullable();
            $table->timestamps();
        });

        Schema::create('visitor_records', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_key', 120)->unique();
            $table->foreignId('vehicle_crossing_id')->nullable()->unique()->constrained('vehicle_crossings')->nullOnDelete();
            $table->string('gate', 30)->index();
            $table->foreignId('camera_id')->nullable()->constrained('cameras')->nullOnDelete();
            $table->string('direction', 10)->default('UNKNOWN');
            $table->timestamp('seen_at')->index();
            // active | duplicate (same vehicle sent twice) | dismissed (registered tag read late, false alarm)
            $table->string('status', 20)->default('active')->index();
            $table->string('status_note', 200)->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('visitor_records')->nullOnDelete();
            $table->string('snapshot_path')->nullable();
            $table->string('plate_image_path')->nullable();
            // pending (OCR running) | read | unreadable | corrected (by a guard)
            $table->string('plate_status', 20)->default('pending')->index();
            $table->string('plate_number', 30)->nullable();
            $table->string('plate_key', 20)->nullable()->index();
            $table->decimal('plate_confidence', 5, 3)->nullable();
            $table->string('ocr_plate_number', 30)->nullable();   // what OCR said (kept after a correction)
            $table->json('ocr_details_json')->nullable();
            $table->foreignId('plate_profile_id')->nullable()->constrained('plate_profiles')->nullOnDelete();
            $table->string('vehicle_type', 50)->nullable();
            $table->string('vehicle_color', 30)->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_records');
        Schema::dropIfExists('plate_profiles');
    }
};
