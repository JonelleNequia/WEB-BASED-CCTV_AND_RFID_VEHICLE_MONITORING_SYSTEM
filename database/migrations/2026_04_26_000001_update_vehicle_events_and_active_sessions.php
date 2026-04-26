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
        // =====================================================
        // UPDATE vehicle_events TABLE
        // =====================================================
        
        // Add direction column (IN or OUT)
        if (!Schema::hasColumn('vehicle_events', 'direction')) {
            Schema::table('vehicle_events', function (Blueprint $table) {
                $table->string('direction', 3)->nullable()->after('event_type');
            });
        }
        
        // Add plate_number column (nullable)
        if (!Schema::hasColumn('vehicle_events', 'plate_number')) {
            Schema::table('vehicle_events', function (Blueprint $table) {
                $table->string('plate_number', 20)->nullable()->after('plate_text');
            });
        }
        
        // Remove match_score column if exists
        if (Schema::hasColumn('vehicle_events', 'match_score')) {
            Schema::table('vehicle_events', function (Blueprint $table) {
                $table->dropColumn('match_score');
            });
        }
        
        // Remove plate_confidence column if exists
        if (Schema::hasColumn('vehicle_events', 'plate_confidence')) {
            Schema::table('vehicle_events', function (Blueprint $table) {
                $table->dropColumn('plate_confidence');
            });
        }

        // =====================================================
        // UPDATE active_sessions TABLE
        // =====================================================
        
        // Add plate_number column if not exists
        if (!Schema::hasColumn('active_sessions', 'plate_number')) {
            Schema::table('active_sessions', function (Blueprint $table) {
                $table->string('plate_number', 20)->nullable()->after('plate_text');
            });
        }
        
        // Rename entry_time to time_in if entry_time exists
        if (Schema::hasColumn('active_sessions', 'entry_time') && !Schema::hasColumn('active_sessions', 'time_in')) {
            Schema::table('active_sessions', function (Blueprint $table) {
                $table->renameColumn('entry_time', 'time_in');
            });
        }
        
        // Add time_out column if not exists
        if (!Schema::hasColumn('active_sessions', 'time_out')) {
            Schema::table('active_sessions', function (Blueprint $table) {
                $table->timestamp('time_out')->nullable()->after('time_in');
            });
        }
        
        // Update status default from 'open' to 'active' - SQLite compatible way
        // SQLite doesn't support MODIFY, so we use a workaround
        if (Schema::hasColumn('active_sessions', 'status')) {
            Schema::table('active_sessions', function (Blueprint $table) {
                // Drop the old column and recreate with new default
                $table->dropColumn('status');
            });
            Schema::table('active_sessions', function (Blueprint $table) {
                $table->string('status', 50)->default('active')->after('time_out');
            });
            
            // Update existing 'open' status to 'active'
            DB::table('active_sessions')->where('status', 'open')->update(['status' => 'active']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rollback vehicle_events changes
        Schema::table('vehicle_events', function (Blueprint $table) {
            if (Schema::hasColumn('vehicle_events', 'direction')) {
                $table->dropColumn('direction');
            }
            if (Schema::hasColumn('vehicle_events', 'plate_number')) {
                $table->dropColumn('plate_number');
            }
        });
        
        // Re-add match_score (nullable) for rollback
        Schema::table('vehicle_events', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicle_events', 'match_score')) {
                $table->unsignedInteger('match_score')->nullable()->after('matched_entry_id');
            }
        });
        
        // Re-add plate_confidence for rollback
        Schema::table('vehicle_events', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicle_events', 'plate_confidence')) {
                $table->decimal('plate_confidence', 5, 2)->nullable()->after('plate_text');
            }
        });
        
        // Rollback active_sessions changes
        Schema::table('active_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('active_sessions', 'plate_number')) {
                $table->dropColumn('plate_number');
            }
            if (Schema::hasColumn('active_sessions', 'time_in') && !Schema::hasColumn('active_sessions', 'entry_time')) {
                $table->renameColumn('time_in', 'entry_time');
            }
            if (Schema::hasColumn('active_sessions', 'time_out')) {
                $table->dropColumn('time_out');
            }
        });
        
        // Revert status column
        Schema::table('active_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('active_sessions', 'status')) {
                $table->dropColumn('status');
            }
        });
        Schema::table('active_sessions', function (Blueprint $table) {
            $table->string('status', 50)->default('open')->after('time_in');
        });
    }
};