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
        Schema::create('guest_vehicle_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('plate_text', 50)->nullable()->index();
            $table->string('vehicle_type', 50)->nullable();
            $table->string('vehicle_color', 50)->nullable();
            $table->string('location', 30)->default('parking')->index();
            $table->string('observation_source', 30)->default('manual')->index();
            $table->timestamp('observed_at')->index();
            $table->foreignId('camera_id')->nullable()->constrained()->nullOnDelete();
            $table->string('snapshot_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_vehicle_observations');
    }
};

