<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gate direction work, Phase D: Visitors › Review.
 *
 * - visitor_records.reviewed_at / reviewed_by: a guard has looked at the
 *   plate (confirmed, corrected, picked a match, no plate visible).
 * - plate_profiles.owner_name: the name a guard gave this plate.
 * - review_actions: every Review action (who, when, the value before and
 *   after) for the audit log.
 *
 * Plates corrected before count as reviewed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_records', function (Blueprint $table): void {
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('plate_profiles', function (Blueprint $table): void {
            $table->string('owner_name', 120)->nullable();
        });

        Schema::create('review_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visitor_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plate_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20);
            $table->string('from_value', 200)->nullable();
            $table->string('to_value', 200)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });

        DB::table('visitor_records')->whereNotNull('corrected_by')->update([
            'reviewed_at' => DB::raw('corrected_at'),
            'reviewed_by' => DB::raw('corrected_by'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('review_actions');
        Schema::table('plate_profiles', function (Blueprint $table): void {
            $table->dropColumn('owner_name');
        });
        Schema::table('visitor_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn('reviewed_at');
        });
    }
};
