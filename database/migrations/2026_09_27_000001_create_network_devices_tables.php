<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plug-and-detect: devices found on the network, identified by MAC address
 * (the IP may change), and which station uses which camera and reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_devices', function (Blueprint $table): void {
            $table->id();
            // MAC address when known, otherwise "ip:<address>" (device on another subnet).
            $table->string('device_key')->unique();
            $table->string('mac', 17)->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->string('kind', 20)->default('unknown'); // camera | rfid_reader | router | unknown
            $table->string('confidence', 20)->default('none'); // confirmed | possible | none
            $table->string('status', 20)->default('online'); // online | offline | unreachable
            $table->string('vendor')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('name')->nullable();
            $table->string('interface', 100)->nullable();
            $table->string('subnet', 50)->nullable();
            $table->json('details')->nullable();
            $table->boolean('is_new')->default(true);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ip_changed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('station', 20); // entrance | exit
            $table->string('role', 20); // camera | reader
            $table->foreignId('network_device_id')->constrained('network_devices')->cascadeOnDelete();
            $table->json('options')->nullable();
            $table->timestamps();
            // One camera and one reader per station.
            $table->unique(['station', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_assignments');
        Schema::dropIfExists('network_devices');
    }
};
