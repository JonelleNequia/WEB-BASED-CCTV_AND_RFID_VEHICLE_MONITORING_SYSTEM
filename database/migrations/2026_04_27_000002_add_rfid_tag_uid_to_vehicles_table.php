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
        if (! Schema::hasColumn('vehicles', 'rfid_tag_uid')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->string('rfid_tag_uid', 100)->nullable()->unique()->after('id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('vehicles', 'rfid_tag_uid')) {
            Schema::table('vehicles', function (Blueprint $table): void {
                $table->dropColumn('rfid_tag_uid');
            });
        }
    }
};
