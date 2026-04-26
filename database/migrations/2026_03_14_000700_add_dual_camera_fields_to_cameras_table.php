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
        Schema::table('cameras', function (Blueprint $table): void {
            $table->string('camera_role', 20)->nullable()->after('camera_name')->index();
            $table->string('source_username')->nullable()->after('source_value');
            $table->string('source_password')->nullable()->after('source_username');
            $table->string('browser_device_id')->nullable()->after('source_password');
            $table->string('browser_label')->nullable()->after('browser_device_id');
            $table->json('calibration_mask_json')->nullable()->after('browser_label');
            $table->json('calibration_line_json')->nullable()->after('calibration_mask_json');
            $table->string('last_connection_status')->nullable()->after('calibration_line_json');
            $table->text('last_connection_message')->nullable()->after('last_connection_status');
            $table->timestamp('last_connected_at')->nullable()->after('last_connection_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropColumn([
                'camera_role',
                'source_username',
                'source_password',
                'browser_device_id',
                'browser_label',
                'calibration_mask_json',
                'calibration_line_json',
                'last_connection_status',
                'last_connection_message',
                'last_connected_at',
            ]);
        });
    }
};
