<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountSecurityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountMigrationTest extends TestCase
{
    public function test_upgrade_preserves_existing_hashes_roles_and_completion_is_idempotent(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'philcst-account-upgrade-');
        $original = config('database.default');
        config(['database.default' => 'account_upgrade', 'database.connections.account_upgrade' => [
            ...config('database.connections.sqlite'), 'database' => $database, 'url' => null,
        ]]);
        try {
            $paths = collect(glob(database_path('migrations/*.php')))->reject(fn ($path) => str_contains($path, '000002_account_provisioning'))
                ->map(fn ($path) => str_replace(base_path().'/', '', $path))->values()->all();
            Artisan::call('migrate', ['--database' => 'account_upgrade', '--path' => $paths, '--force' => true]);
            $oldHash = password_hash('existing owner password', PASSWORD_BCRYPT, ['cost' => 4]);
            DB::table('users')->insert(['name' => 'Existing Owner', 'email' => 'existing@school.test', 'role' => 'admin', 'password' => $oldHash]);
            Artisan::call('migrate', ['--database' => 'account_upgrade', '--force' => true]);
            $user = User::query()->sole();
            $this->assertSame(['Existing Owner', 'existing@school.test', 'admin', $oldHash, 0], [$user->name, $user->email, $user->role, $user->password, $user->password_policy_version]);
            $this->assertNotNull(DB::table('account_security_state')->value('setup_completed_at'));
            $this->assertNull(app(AccountSecurityService::class)->issueSetup('new@school.test', 'New', initialize: true));
            $user->forceFill(['password_policy_version' => 1])->save();
            Artisan::call('migrate', ['--database' => 'account_upgrade', '--force' => true]);
            $this->assertSame(1, $user->fresh()->password_policy_version);
            User::query()->delete();
            $this->assertNull(app(AccountSecurityService::class)->issueSetup('new@school.test', 'New', initialize: true, replace: true));
        } finally {
            DB::disconnect('account_upgrade');
            config(['database.default' => $original]);
            foreach ([$database, $database.'-wal', $database.'-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
