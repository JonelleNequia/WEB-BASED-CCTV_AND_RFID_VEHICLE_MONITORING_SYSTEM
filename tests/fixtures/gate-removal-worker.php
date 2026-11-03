<?php

// Separate process used only with a temporary database by GateRemovalConcurrencyTest.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = $argv[1];
if (! str_starts_with(realpath($database), realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('This fixture requires a temporary database.');
}
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
    'database.connections.sqlite.url' => null, 'cache.default' => 'array']);
Illuminate\Support\Facades\DB::purge('sqlite');
$service = new class extends App\Services\GateManagementService
{
    // The concurrency test exercises database archival and assignment release;
    // it must not write application runtime files or contact hardware.
    public function refreshRuntime(): void {}
};
if ($argv[2] === 'initialize') {
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $service->create('Third gate');
    exit(0);
}
echo "ready\n";
flush();
try {
    $service->archive($argv[2]);
    echo "archived\n";
} catch (Illuminate\Validation\ValidationException $exception) {
    echo "minimum rejected\n";
    exit(2);
}
