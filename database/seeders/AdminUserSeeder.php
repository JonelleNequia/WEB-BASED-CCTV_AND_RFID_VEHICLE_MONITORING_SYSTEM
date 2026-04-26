<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the single admin account for the capstone demo.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@philcst.local'],
            [
                'name' => 'PHILCST Administrator',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
            ]
        );
    }
}
