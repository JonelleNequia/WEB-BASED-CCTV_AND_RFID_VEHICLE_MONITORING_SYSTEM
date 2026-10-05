<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A2 (detection): every vehicle type a guard corrects, to see how often the
 * camera is wrong (php artisan detection:accuracy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_type_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visitor_record_id')->nullable()->constrained('visitor_records')->nullOnDelete();
            $table->foreignId('vehicle_crossing_id')->nullable()->constrained('vehicle_crossings')->nullOnDelete();
            $table->string('gate')->index();
            $table->string('detected_type')->nullable();
            $table->string('corrected_type');
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_type_corrections');
    }
};
