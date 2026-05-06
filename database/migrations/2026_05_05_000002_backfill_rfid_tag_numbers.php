<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('vehicle_rfid_tags', 'tag_number')) {
            return;
        }

        $usedNumbers = DB::table('vehicle_rfid_tags')
            ->whereNotNull('tag_number')
            ->pluck('tag_number')
            ->map(fn ($number): int => (int) $number)
            ->all();

        $nextNumber = 1;

        DB::table('vehicle_rfid_tags')
            ->whereNull('tag_number')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($tags) use (&$usedNumbers, &$nextNumber): void {
                foreach ($tags as $tag) {
                    while (in_array($nextNumber, $usedNumbers, true)) {
                        $nextNumber++;
                    }

                    DB::table('vehicle_rfid_tags')
                        ->where('id', $tag->id)
                        ->update(['tag_number' => $nextNumber]);

                    $usedNumbers[] = $nextNumber;
                    $nextNumber++;
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep assigned tag numbers on rollback to avoid renumbering inventory records.
    }
};
