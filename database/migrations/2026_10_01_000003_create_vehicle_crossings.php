<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (visitor model): direction from the CCTV.
 *
 * - vehicle_crossings: one row per vehicle the camera saw cross a gate's
 *   trigger line, with the direction (IN / OUT / UNKNOWN), time, track ID,
 *   snapshot and confidence. Phase 3 links RFID reads to these rows.
 * - cameras.calibration_line_json gets "in_side" (+1 / -1): which side of the
 *   line a vehicle moves TO when it goes IN. Existing lines get +1, which is
 *   what the detector assumed before (moving to the +1 side was IN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_crossings', function (Blueprint $table): void {
            $table->id();
            $table->string('gate', 30)->index();
            $table->foreignId('camera_id')->nullable()->constrained('cameras')->nullOnDelete();
            $table->string('direction', 10)->index(); // IN, OUT, UNKNOWN
            $table->string('direction_reason', 120)->nullable();
            $table->timestamp('crossed_at')->index();
            $table->unsignedBigInteger('track_id')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('vehicle_type', 50)->nullable();
            $table->string('snapshot_path')->nullable();
            $table->string('external_event_key', 120)->unique();
            $table->json('detection_metadata_json')->nullable();
            $table->timestamps();
        });

        foreach (DB::table('cameras')->whereNotNull('calibration_line_json')->get(['id', 'calibration_line_json']) as $camera) {
            $line = json_decode((string) $camera->calibration_line_json, true);

            if (is_array($line) && ! array_key_exists('in_side', $line)) {
                $line['in_side'] = 1;
                DB::table('cameras')->where('id', $camera->id)->update(['calibration_line_json' => json_encode($line)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('cameras')->whereNotNull('calibration_line_json')->get(['id', 'calibration_line_json']) as $camera) {
            $line = json_decode((string) $camera->calibration_line_json, true);

            if (is_array($line) && array_key_exists('in_side', $line)) {
                unset($line['in_side']);
                DB::table('cameras')->where('id', $camera->id)->update(['calibration_line_json' => json_encode($line)]);
            }
        }

        Schema::dropIfExists('vehicle_crossings');
    }
};
