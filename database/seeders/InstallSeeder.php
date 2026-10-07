<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Windows install kit: what a new install needs (the gates' camera records,
 * zones and default settings), without the demo admin account: the
 * installer creates the first admin with a random password
 * (system:first-admin). Run once, on a new database only.
 */
class InstallSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CameraSeeder::class,
            RoiSeeder::class,
            SystemSettingSeeder::class,
        ]);
    }
}
