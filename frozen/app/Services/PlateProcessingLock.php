<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Serialize identity writes before reads, including on SQLite. */
class PlateProcessingLock
{
    public function run(callable $work): mixed
    {
        return DB::transaction(function () use ($work) {
            DB::table('plate_processing_locks')->where('id', 1)->increment('version');

            return $work();
        }, 5);
    }
}
