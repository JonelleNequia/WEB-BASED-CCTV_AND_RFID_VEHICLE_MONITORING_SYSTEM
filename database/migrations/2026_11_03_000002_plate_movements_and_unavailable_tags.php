<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_events', function (Blueprint $table): void {
            $table->foreignId('visitor_record_id')->nullable()->unique()->constrained('visitor_records')->nullOnDelete();
            $table->boolean('occupancy_managed')->default(false);
        });
        Schema::create('vehicle_occupancy_baselines', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')->primary()->constrained('vehicles')->cascadeOnDelete();
            $table->text('snapshot_json');
        });
        Schema::create('unavailable_rfid_uids', function (Blueprint $table): void {
            $table->string('uid', 100)->primary();
            $table->foreignId('tag_id')->nullable()->constrained('vehicle_rfid_tags')->nullOnDelete();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_status', 20)->nullable();
            $table->timestamp('marked_at');
        });
        // A deny record preserves the existing lost meaning, without repairing
        // assignments, inventing an actor or rewriting any historical row.
        foreach (DB::table('vehicle_rfid_tags')->where('status', 'lost')->get() as $tag) {
            foreach (array_unique([$tag->uid, $tag->tag_uid]) as $raw) {
                $uid = \App\Models\RfidTag::normalizeUid((string) $raw);
                if ($uid !== '') {
                    DB::table('unavailable_rfid_uids')->insertOrIgnore([
                        'uid' => $uid, 'tag_id' => $tag->id, 'marked_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('vehicle_events', function (Blueprint $table): void {
            $table->dropUnique(['visitor_record_id']);
            $table->dropConstrainedForeignId('visitor_record_id');
            $table->dropColumn('occupancy_managed');
        });
        Schema::dropIfExists('vehicle_occupancy_baselines');
        Schema::dropIfExists('unavailable_rfid_uids');
    }
};
