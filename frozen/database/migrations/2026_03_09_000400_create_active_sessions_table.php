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
        Schema::create('active_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_event_id')->unique()->constrained('vehicle_events')->cascadeOnDelete();
            $table->string('plate_text', 50)->nullable()->index();
            $table->string('vehicle_type')->nullable();
            $table->string('vehicle_color')->nullable();
            $table->timestamp('entry_time')->index();
            $table->string('status')->default('open')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('active_sessions');
    }
};
