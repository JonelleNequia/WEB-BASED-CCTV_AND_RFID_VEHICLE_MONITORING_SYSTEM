<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CCTV Detection: added.
 *
 * Stores CCTV cameras found on the LAN and links the fixed entrance/exit
 * camera rows to a detected camera instead of a manually typed source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovered_cameras', function (Blueprint $table): void {
            $table->id();
            $table->string('device_key')->unique();
            $table->string('ip_address', 64);
            $table->string('mac_address', 32)->nullable()->index();
            $table->string('onvif_xaddr')->nullable();
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('firmware_version', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('name', 150)->nullable();
            $table->json('discovery_methods')->nullable();
            $table->string('auth_status', 30)->default('unknown');
            $table->string('username', 100)->nullable();
            $table->text('password')->nullable();
            $table->text('main_stream_uri')->nullable();
            $table->text('sub_stream_uri')->nullable();
            $table->string('pending_role', 20)->nullable();
            $table->string('thumbnail_file', 100)->nullable();
            $table->timestamp('thumbnail_updated_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('cameras', function (Blueprint $table): void {
            $table->unsignedBigInteger('discovered_camera_id')->nullable()->index();
            $table->unsignedBigInteger('calibration_device_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropIndex(['discovered_camera_id']);
            $table->dropColumn(['discovered_camera_id', 'calibration_device_id']);
        });

        Schema::dropIfExists('discovered_cameras');
    }
};
