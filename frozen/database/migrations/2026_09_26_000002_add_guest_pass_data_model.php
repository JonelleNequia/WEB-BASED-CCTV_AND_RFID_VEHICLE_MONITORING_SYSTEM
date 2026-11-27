<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: guest pass (temporary RFID) data model.
 *
 * - vehicle_rfid_tags: tag_type (vehicle | guest_pass), display_number (G-01),
 *   and the wider status set (available | assigned | issued | lost | disabled).
 * - guest_visits: one row per issued pass. The pass is not tied to a person;
 *   every issue is a new visit.
 * - vehicle_events.guest_visit_id links ENTRY/EXIT events to a visit.
 * - active_sessions.archived_at / archive_reason record stale sessions closed
 *   by "php artisan guests:close-stale-sessions".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_rfid_tags', function (Blueprint $table): void {
            $table->string('tag_type', 20)->default('vehicle')->index();
            $table->string('display_number', 20)->nullable()->unique();
        });

        // The old "inactive" status is now called "disabled".
        DB::table('vehicle_rfid_tags')->where('status', 'inactive')->update(['status' => 'disabled']);

        Schema::create('guest_visits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rfid_tag_id')->index();
            // Set to rfid_tag_id only while the visit is active. The unique
            // index makes it impossible to issue a pass that is already out.
            $table->unsignedBigInteger('active_rfid_tag_id')->nullable()->unique();
            $table->string('plate', 50)->nullable()->index();
            $table->string('driver_name', 150)->nullable();
            $table->string('vehicle_type', 50)->nullable();
            $table->string('color', 50)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->string('destination', 150)->nullable();
            $table->string('id_presented', 100)->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->dateTime('entry_at')->nullable()->index();
            $table->string('entry_snapshot')->nullable();
            $table->dateTime('exit_at')->nullable();
            $table->string('exit_snapshot')->nullable();
            $table->dateTime('valid_until')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('vehicle_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('guest_visit_id')->nullable()->index();
        });

        Schema::table('active_sessions', function (Blueprint $table): void {
            $table->dateTime('archived_at')->nullable();
            $table->string('archive_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('active_sessions', function (Blueprint $table): void {
            $table->dropColumn(['archived_at', 'archive_reason']);
        });

        Schema::table('vehicle_events', function (Blueprint $table): void {
            $table->dropIndex(['guest_visit_id']);
            $table->dropColumn('guest_visit_id');
        });

        Schema::dropIfExists('guest_visits');

        DB::table('vehicle_rfid_tags')->where('status', 'disabled')->update(['status' => 'inactive']);

        Schema::table('vehicle_rfid_tags', function (Blueprint $table): void {
            $table->dropUnique(['display_number']);
            $table->dropIndex(['tag_type']);
            $table->dropColumn(['tag_type', 'display_number']);
        });
    }
};
