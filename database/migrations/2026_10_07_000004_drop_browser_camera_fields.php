<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calibration work: calibration draws on the gate's own camera stream, so
 * the browser camera it used to pick (getUserMedia device ID and label) is
 * no longer kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropColumn(['browser_device_id', 'browser_label']);
        });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->string('browser_device_id')->nullable()->after('source_password');
            $table->string('browser_label')->nullable()->after('browser_device_id');
        });
    }
};
