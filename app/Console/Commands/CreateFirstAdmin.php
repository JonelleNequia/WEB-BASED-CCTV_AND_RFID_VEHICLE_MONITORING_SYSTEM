<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Windows install kit: the first admin account, with a random password
 * (no default password in a public repo). The installer shows it once and
 * saves it in INSTALL-RESULT.txt; change it after the first sign-in.
 * Does nothing when an admin already exists.
 *
 *   php artisan system:first-admin --email=admin@school.local --json
 */
class CreateFirstAdmin extends Command
{
    protected $signature = 'system:first-admin {--email=admin@philcst.local} {--name=Administrator} {--json : Print the result as JSON}';

    protected $description = 'Create the first admin account with a random password (only when there is no admin)';

    public function handle(): int
    {
        if (User::query()->where('role', User::ROLE_ADMIN)->exists()) {
            $result = ['created' => false, 'email' => User::query()->where('role', User::ROLE_ADMIN)->value('email')];
        } else {
            $password = Str::password(16, symbols: false);
            User::query()->create([
                'name' => (string) $this->option('name'),
                'email' => (string) $this->option('email'),
                'password' => $password,
                'role' => User::ROLE_ADMIN,
            ]);
            $result = ['created' => true, 'email' => (string) $this->option('email'), 'password' => $password];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result));
        } else {
            $this->line($result['created']
                ? "Admin created: {$result['email']} / {$result['password']} (change it after signing in)"
                : "An admin already exists ({$result['email']}); nothing changed.");
        }

        return self::SUCCESS;
    }
}
