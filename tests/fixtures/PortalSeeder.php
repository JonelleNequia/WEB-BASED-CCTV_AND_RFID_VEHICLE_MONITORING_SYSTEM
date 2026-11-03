<?php

namespace Tests\fixtures;

use App\Models\User;
use Illuminate\Database\Seeder;

/** Test-only account fixtures; tests/ is excluded from release archives. */
class PortalSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(\Database\Seeders\DatabaseSeeder::class);
        if (! User::query()->where('email', 'admin@philcst.local')->exists()) {
            User::factory()->create(['name' => 'Test Administrator', 'email' => 'admin@philcst.local', 'role' => 'admin']);
        }
    }
}
