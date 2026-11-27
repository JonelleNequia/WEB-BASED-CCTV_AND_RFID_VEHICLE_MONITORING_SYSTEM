<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rfid_scan_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_rfid_tag_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('correlated_vehicle_event_id')->nullable()->constrained('vehicle_events')->nullOnDelete();
            $table->string('tag_uid', 100)->index();
            $table->string('scan_location', 30)->index();
            $table->string('scan_direction', 30)->nullable()->index();
            $table->string('reader_name')->nullable();
            $table->timestamp('scan_time')->index();
            $table->string('verification_status')->default('verified')->index();
            $table->string('source_mode')->default('simulated')->index();
            $table->json('payload_json')->nullable();
            $table->string('payload_file_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rfid_scan_logs');
    }
};
