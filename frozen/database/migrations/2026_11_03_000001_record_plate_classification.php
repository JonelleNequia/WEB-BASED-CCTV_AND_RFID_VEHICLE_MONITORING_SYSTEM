<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_records', function (Blueprint $table): void {
            $table->string('registration_status', 20)->nullable();
            $table->string('registration_category', 40)->nullable();
            $table->string('registration_reason', 200)->nullable();
        });
        Schema::create('plate_processing_locks', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('version')->default(0);
        });
        DB::table('plate_processing_locks')->insert(['id' => 1, 'version' => 0]);
    }

    public function down(): void
    {
        Schema::table('visitor_records', fn (Blueprint $table) => $table->dropColumn(['registration_status', 'registration_category', 'registration_reason']));
        Schema::dropIfExists('plate_processing_locks');
    }
};
