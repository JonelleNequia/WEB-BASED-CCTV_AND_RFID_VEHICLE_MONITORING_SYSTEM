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
        if (! Schema::hasColumn('vehicle_events', 'direction')) {
            Schema::table('vehicle_events', function (Blueprint $table): void {
                $table->string('direction', 3)->nullable()->after('event_type');
            });
        }

        if (! Schema::hasColumn('vehicle_events', 'plate_number')) {
            Schema::table('vehicle_events', function (Blueprint $table): void {
                $table->string('plate_number', 20)->nullable()->after('plate_text');
            });
        }

        if (! Schema::hasColumn('active_sessions', 'plate_number')) {
            Schema::table('active_sessions', function (Blueprint $table): void {
                $table->string('plate_number', 20)->nullable()->after('plate_text');
            });
        }

        if (! Schema::hasColumn('active_sessions', 'time_out')) {
            Schema::table('active_sessions', function (Blueprint $table): void {
                $table->timestamp('time_out')->nullable()->after('entry_time');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_events', function (Blueprint $table): void {
            if (Schema::hasColumn('vehicle_events', 'direction')) {
                $table->dropColumn('direction');
            }

            if (Schema::hasColumn('vehicle_events', 'plate_number')) {
                $table->dropColumn('plate_number');
            }
        });

        Schema::table('active_sessions', function (Blueprint $table): void {
            if (Schema::hasColumn('active_sessions', 'plate_number')) {
                $table->dropColumn('plate_number');
            }

            if (Schema::hasColumn('active_sessions', 'time_out')) {
                $table->dropColumn('time_out');
            }
        });
    }
};
