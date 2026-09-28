<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live-latency work: the live view and detection use the camera's sub stream
 * (source_value); the full-resolution main stream is opened only on a
 * trigger, for snapshots and plate reading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->text('snapshot_source_value')->nullable()->after('source_value');
        });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropColumn('snapshot_source_value');
        });
    }
};
