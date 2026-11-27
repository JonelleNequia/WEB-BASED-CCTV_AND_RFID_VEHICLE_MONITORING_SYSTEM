<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Kept for old deployment commands; never creates or overwrites accounts. */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn('Account seeding is disabled. Use the protected system:first-admin setup workflow.');
    }
}
