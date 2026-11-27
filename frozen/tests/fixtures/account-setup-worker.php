<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = $argv[1];
if (! str_starts_with(realpath($database), realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Temporary database required.');
}
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
    'database.connections.sqlite.url' => null, 'cache.default' => 'array']);
Illuminate\Support\Facades\DB::purge('sqlite');
$security = app(App\Services\AccountSecurityService::class);
$delivery = $database.'.test-delivery';
if (str_starts_with($argv[2], 'initialize')) {
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $code = $security->issueSetup('owner@school.test', 'Owner', initialize: true);
    if ($argv[2] === 'initialize-activation') {
        $admin = $security->completeSetup($code, 'quiet rivers carry seven silver boats');
        [$user, $code] = $security->createAccount($admin, ['name' => 'Guard', 'email' => 'guard@school.test', 'role' => 'guard']);
    }
    touch($delivery);
    chmod($delivery, 0600);
    file_put_contents($delivery, $code);
    exit(0);
}
$code = file_get_contents($delivery);
echo "ready\n";
flush();
$user = $argv[2] === 'activation'
    ? $security->activate('guard@school.test', $code, 'quiet rivers carry seven silver boats')
    : $security->completeSetup($code, 'quiet rivers carry seven silver boats');
echo $user ? "completed\n" : "rejected\n";
exit($user ? 0 : 2);
