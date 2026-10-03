<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 (visitor model): cleanup of the older guest records.
 *
 * - guest_vehicle_observations: archived_at / archive_reason (e.g. the same
 *   unknown tag read over and over, which used to create a guest record
 *   every time) and visitor_record_id (a real camera detection converted
 *   into an Unregistered Visitor record). Nothing is deleted; archived and
 *   converted rows are no longer shown or counted as guests.
 * - visitor_records.source: camera (detector), manual (typed by a guard),
 *   legacy (converted from an older guest record).
 *
 * The data itself is changed by `php artisan visitors:cleanup` (with
 * --dry-run first), not by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_vehicle_observations', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
            $table->string('archive_reason', 200)->nullable();
            $table->foreignId('visitor_record_id')->nullable()->constrained('visitor_records')->nullOnDelete();
        });

        Schema::table('visitor_records', function (Blueprint $table): void {
            $table->string('source', 20)->default('camera')->after('external_event_key');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_records', function (Blueprint $table): void {
            $table->dropColumn('source');
        });

        Schema::table('guest_vehicle_observations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('visitor_record_id');
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at', 'archive_reason']);
        });
    }
};
