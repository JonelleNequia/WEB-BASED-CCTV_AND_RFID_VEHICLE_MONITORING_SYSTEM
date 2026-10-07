<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Testing: a gate can use this PC's webcam for a while instead of its CCTV
 * (number of the webcam, 0 = built-in). The CCTV assignment stays and is
 * used again when the webcam is switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->unsignedTinyInteger('test_webcam_index')->nullable()->after('snapshot_source_value');
        });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropColumn('test_webcam_index');
        });
    }
};
