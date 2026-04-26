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
        Schema::table('vehicle_events', function (Blueprint $table): void {
            $table->string('vehicle_category', 30)->nullable()->after('vehicle_color')->index();
            $table->string('resulting_state', 20)->nullable()->after('match_status')->index();
            $table->unsignedInteger('daily_entries_count')->nullable()->after('resulting_state');
            $table->unsignedInteger('daily_exits_count')->nullable()->after('daily_entries_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_events', function (Blueprint $table): void {
            $table->dropColumn([
                'vehicle_category',
                'resulting_state',
                'daily_entries_count',
                'daily_exits_count',
            ]);
        });
    }
};

