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
        if (! Schema::hasColumn('vehicle_events', 'plate_confidence')) {
            Schema::table('vehicle_events', function (Blueprint $table): void {
                $table->decimal('plate_confidence', 5, 2)->nullable()->after('plate_text');
            });
        }

        if (! Schema::hasColumn('vehicle_events', 'match_score')) {
            Schema::table('vehicle_events', function (Blueprint $table): void {
                $table->unsignedInteger('match_score')->nullable()->after('matched_entry_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('vehicle_events', 'match_score')) {
            Schema::table('vehicle_events', function (Blueprint $table): void {
                $table->dropColumn('match_score');
            });
        }

        if (Schema::hasColumn('vehicle_events', 'plate_confidence')) {
            Schema::table('vehicle_events', function (Blueprint $table): void {
                $table->dropColumn('plate_confidence');
            });
        }
    }
};
