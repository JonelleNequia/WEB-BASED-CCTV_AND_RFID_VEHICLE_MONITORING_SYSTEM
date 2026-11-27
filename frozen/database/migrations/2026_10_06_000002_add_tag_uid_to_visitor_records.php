<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFID only with a vehicle: an unknown tag read for a vehicle the camera saw
 * is kept on its Unregistered Visitor record ("Register this tag").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_records', function (Blueprint $table): void {
            $table->string('tag_uid', 100)->nullable()->after('plate_number');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_records', function (Blueprint $table): void {
            $table->dropColumn('tag_uid');
        });
    }
};
