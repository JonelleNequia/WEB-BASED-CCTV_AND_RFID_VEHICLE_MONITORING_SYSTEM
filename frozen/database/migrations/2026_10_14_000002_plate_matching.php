<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gate direction work, Phase C: the same vehicle across passes and gates.
 *
 * - visitor_records.match_confidence: how the record was tied to its plate
 *   profile: exact (same plate), high / medium (one character off, same
 *   type and colour, a sensible time: tied), low (not tied: suggested_profile_id
 *   waits in Review), manual (a guard typed the plate), learned (a read a
 *   guard corrected before, turned into the right plate).
 * - plate_corrections: every guard correction of a camera read (camera read
 *   -> right plate), used on later reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_records', function (Blueprint $table): void {
            $table->string('match_confidence', 10)->nullable();
            $table->string('match_reason', 160)->nullable();
            $table->foreignId('suggested_profile_id')->nullable()->constrained('plate_profiles')->nullOnDelete();
        });

        Schema::create('plate_corrections', function (Blueprint $table): void {
            $table->id();
            $table->string('read_key', 20);
            $table->string('plate_key', 20);
            $table->unsignedInteger('times')->default(1);
            $table->foreignId('last_corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['read_key', 'plate_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plate_corrections');
        Schema::table('visitor_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('suggested_profile_id');
            $table->dropColumn(['match_confidence', 'match_reason']);
        });
    }
};
