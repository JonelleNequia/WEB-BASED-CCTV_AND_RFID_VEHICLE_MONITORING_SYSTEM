<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('vehicle_rfid_tags')
            ->where('status', 'active')
            ->whereNull('vehicle_id')
            ->update([
                'status' => 'available',
                'assigned_at' => null,
            ]);

        DB::table('vehicle_rfid_tags')
            ->where('status', 'active')
            ->whereNotNull('vehicle_id')
            ->update(['status' => 'assigned']);

        DB::table('vehicle_rfid_tags')
            ->where('status', 'assigned')
            ->whereNull('vehicle_id')
            ->update([
                'status' => 'available',
                'assigned_at' => null,
            ]);

        DB::table('vehicle_rfid_tags')
            ->where('status', 'assigned')
            ->whereNotNull('vehicle_id')
            ->orderBy('id')
            ->select(['id', 'vehicle_id', 'uid', 'tag_uid'])
            ->chunkById(100, function ($tags): void {
                foreach ($tags as $tag) {
                    DB::table('vehicles')
                        ->where('id', $tag->vehicle_id)
                        ->whereNull('rfid_tag_id')
                        ->update([
                            'rfid_tag_id' => $tag->id,
                            'rfid_tag_uid' => $tag->uid ?? $tag->tag_uid,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
