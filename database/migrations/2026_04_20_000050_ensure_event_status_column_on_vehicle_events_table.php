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
        if (! Schema::hasColumn('vehicle_events', 'event_status')) {
            Schema::table('vehicle_events', function (Blueprint $table): void {
                $table->string('event_status')->default('completed')->after('match_status')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('vehicle_events', 'event_status')) {
            Schema::table('vehicle_events', function (Blueprint $table): void {
                $table->dropColumn('event_status');
            });
        }
    }
};

