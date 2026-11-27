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
        Schema::create('vehicle_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type')->index();
            $table->string('plate_text', 50)->nullable()->index();
            $table->decimal('plate_confidence', 5, 2)->nullable();
            $table->string('vehicle_type')->nullable();
            $table->string('vehicle_color')->nullable();
            $table->foreignId('camera_id')->nullable()->constrained()->nullOnDelete();
            $table->string('roi_name')->nullable();
            $table->timestamp('event_time')->index();
            $table->string('vehicle_image_path')->nullable();
            $table->string('plate_image_path')->nullable();
            $table->foreignId('matched_entry_id')->nullable()->constrained('vehicle_events')->nullOnDelete();
            $table->unsignedInteger('match_score')->nullable();
            $table->string('match_status')->default('open')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_events');
    }
};
