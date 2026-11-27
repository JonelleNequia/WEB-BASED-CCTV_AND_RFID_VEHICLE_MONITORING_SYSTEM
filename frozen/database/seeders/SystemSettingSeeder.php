<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    /**
     * Seed the default system settings used by the matching service and UI.
     */
    public function run(): void
    {
        $settings = [
            'matching_threshold_matched' => '75',
            'matching_threshold_manual_review' => '50',
            'operating_mode' => 'manual',
            'deployment_mode' => 'offline_local',
            'cctv_simulation_mode' => 'enabled',
            'rfid_simulation_mode' => 'enabled',
            'camera_source_placeholder' => 'rtsp://philcst-green-metrics-demo',
            'retention_days' => '30',
            // Phase 1: gate names and readers live in the gates table.
        ];

        foreach ($settings as $key => $value) {
            SystemSetting::query()->updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => $value]
            );
        }
    }
}
