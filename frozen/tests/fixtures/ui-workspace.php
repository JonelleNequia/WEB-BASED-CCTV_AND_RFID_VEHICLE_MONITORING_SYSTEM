<?php

// Browser-test data only. Never run against an installation database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$directory = $argv[1] ?? '';
if (! is_dir($directory) || ! str_starts_with(basename($directory), 'philcst-ui-')) {
    throw new RuntimeException('An isolated temporary UI directory is required.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->runningUnitTests() || config('database.connections.sqlite.database') !== $directory.'/ui.sqlite') {
    throw new RuntimeException('Refusing to prepare fixtures outside the isolated UI database.');
}
touch($directory.'/ui.sqlite');
chmod($directory.'/ui.sqlite', 0600);
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => Tests\fixtures\PortalSeeder::class, '--force' => true]);
$password = bin2hex(random_bytes(24));
$user = App\Models\User::query()->where('email', 'admin@philcst.local')->firstOrFail();
$user->forceFill([
    'name' => 'UI Test Administrator',
    'password' => Illuminate\Support\Facades\Hash::make($password),
    'password_policy_version' => 1,
])->save();
App\Models\User::factory()->create([
    'email' => 'legacy@school.test', 'password' => Illuminate\Support\Facades\Hash::make($password),
    'password_policy_version' => 0,
]);
App\Models\Camera::query()->forRole('gate-1')->firstOrFail()->update([
    'source_type' => 'rtsp', 'source_value' => 'rtsp://192.0.2.1/fixture',
    'calibration_mask_json' => [['x' => .2, 'y' => .2], ['x' => .8, 'y' => .2], ['x' => .8, 'y' => .8], ['x' => .2, 'y' => .8]],
]);
App\Models\Gate::query()->whereIn('code', ['gate-1', 'gate-2'])->update(['is_active' => true]);
App\Models\Gate::query()->where('code', 'gate-1')->update(['away_direction' => 'IN']);
$service = app(App\Services\GateManagementService::class);
$service->create('Service Gate');
$archived = $service->create('Archived fixture gate');
$archived->forceFill(['archived_at' => now(), 'is_active' => false])->save();
file_put_contents($directory.'/login.json', json_encode(['email' => $user->email, 'password' => $password]));
chmod($directory.'/login.json', 0600);
echo "Isolated browser fixtures prepared.\n";
