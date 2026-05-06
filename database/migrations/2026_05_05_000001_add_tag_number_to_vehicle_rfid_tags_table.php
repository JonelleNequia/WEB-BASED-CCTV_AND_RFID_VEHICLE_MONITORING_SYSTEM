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
        if (Schema::hasColumn('vehicle_rfid_tags', 'tag_number')) {
            return;
        }

        Schema::table('vehicle_rfid_tags', function (Blueprint $table): void {
            $table->unsignedInteger('tag_number')
                ->nullable()
                ->unique()
                ->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('vehicle_rfid_tags', 'tag_number')) {
            return;
        }

        Schema::table('vehicle_rfid_tags', function (Blueprint $table): void {
            $table->dropUnique('vehicle_rfid_tags_tag_number_unique');
            $table->dropColumn('tag_number');
        });
    }
};
