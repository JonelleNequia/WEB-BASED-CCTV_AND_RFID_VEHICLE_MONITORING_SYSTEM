<?php

use App\Models\NetworkDevice;
use App\Services\DetectorRuntimeService;
use App\Services\DeviceRegistryService;
use App\Services\DeviceServiceRuntime;
use App\Support\DeviceFiles;
use App\Support\PythonLauncher;
use App\Services\GuestPassService;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

// Phase 1: start (or confirm) the detector without opening a Station page,
// e.g. from Windows Task Scheduler at startup.
Artisan::command('detector:start', function (DetectorRuntimeService $detectorRuntimeService) {
    Cache::forget('detector-runtime-heartbeat');
    $status = $detectorRuntimeService->ensureRunning();

    $this->info((string) ($status['auto_start_message'] ?? 'Detector check finished.'));
    $this->line('Service running: '.(($status['service_running'] ?? false) ? 'yes' : 'no'));
})->purpose('Start the Python vehicle detector in the background');

/*
 * Plug-and-detect diagnostics. Both run the Python device service code
 * directly, so they work even when the background service is stopped.
 */
$runDeviceTool = function (array $arguments, callable $output): int {
    $process = new Process(
        [PythonLauncher::pythonExecutable(), 'device_service.py', ...$arguments],
        PythonLauncher::directory(),
        ['DEVICE_FILES_PATH' => DeviceFiles::directory(), 'PYTHONUNBUFFERED' => '1'],
        null,
        600
    );

    return $process->run(fn (string $type, string $buffer) => $output($buffer));
};

Artisan::command('devices:scan {--target=* : Also probe this IP} {--allow-temp-ip : Use a temporary IP for devices on another subnet (needs admin)}', function (DeviceRegistryService $registry) use ($runDeviceTool) {
    $arguments = ['--scan-once', '--verbose'];
    foreach ((array) $this->option('target') as $target) {
        $arguments[] = '--target';
        $arguments[] = $target;
    }
    if ($this->option('allow-temp-ip')) {
        $arguments[] = '--allow-temp-ip';
    }

    $this->info('Scanning the network for cameras and UHF readers...');
    $verbose = $this->output->isVerbose();
    $exitCode = $runDeviceTool($arguments, function (string $buffer) use ($verbose) {
        if ($verbose) {
            $this->output->write($buffer);
        }
    });

    if ($exitCode !== 0) {
        $this->error('The scan failed. Run with --verbose to see why.');

        return 1;
    }

    $summary = $registry->ingestLastScanFile() ?? [];
    $this->newLine();
    $this->table(
        ['Type', 'Name', 'IP', 'MAC', 'Status', 'Details'],
        NetworkDevice::query()->orderBy('kind')->orderBy('ip')->get()->map(fn (NetworkDevice $device) => [
            $device->kindLabel().($device->confidence === 'possible' ? ' (possible)' : ''),
            $device->name ?: ($device->brand ?: $device->vendor ?: '-'),
            $device->ip,
            $device->mac ?: '-',
            $device->status,
            $device->kind === NetworkDevice::KIND_CAMERA
                ? 'RTSP '.($device->cameraDetails()['rtsp_port'] ?? '-').($device->cameraDetails()['onvif_xaddr'] ?? null ? ' · ONVIF' : '')
                : ($device->kind === NetworkDevice::KIND_READER
                    ? strtoupper($device->readerDetails()['transport'] ?? '?').' '.($device->readerDetails()['port'] ?? '-')
                        .' · '.($device->readerDetails()['protocol'] ?? 'format unknown')
                        .' · '.($device->readerDetails()['work_mode'] ?? 'mode unknown')
                    : ($device->vendor ?: '-')),
        ])->all()
    );
    $this->line(sprintf('New: %d · Updated: %d · Moved to a new IP: %d · Now offline: %d',
        $summary['created'] ?? 0, $summary['updated'] ?? 0, $summary['moved'] ?? 0, $summary['offline'] ?? 0));

    return 0;
})->purpose('Find cameras and UHF RFID readers on the network (use --verbose to see every step)');

Artisan::command('devices:listen {address : Reader IP:PORT} {--udp : Use UDP instead of TCP} {--seconds=30}', function () use ($runDeviceTool) {
    $arguments = ['--listen', (string) $this->argument('address'), '--seconds', (string) (int) $this->option('seconds')];
    if ($this->option('udp')) {
        $arguments[] = '--transport';
        $arguments[] = 'udp';
    }

    return $runDeviceTool($arguments, fn (string $buffer) => $this->output->write($buffer));
})->purpose('Print the raw data a UHF reader sends (hold a tag near it)');

// Find my reader in the terminal. Passive listening (readers on another
// network) needs admin rights: on macOS/Linux this asks for your password.
Artisan::command('devices:find {--seconds=90} {--no-passive : Skip raw packet listening (no password needed)}', function () {
    $python = PythonLauncher::pythonExecutable();
    $arguments = [$python, 'device_service.py', '--find', '--post', '--seconds', (string) (int) $this->option('seconds')];
    $environment = [
        'DEVICE_FILES_PATH' => DeviceFiles::directory(),
        'PYTHONUNBUFFERED' => '1',
        'PYTHONDONTWRITEBYTECODE' => '1',
    ];

    if ($this->option('no-passive')) {
        $arguments[] = '--no-passive';
    } elseif (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        // Only the Python part runs as admin, with its own temp folder, so no
        // root-owned files end up in the project.
        $this->line('Passive listening needs admin rights; enter your computer password if asked.');
        $arguments = ['sudo', 'env',
            'PYTHONUNBUFFERED=1', 'PYTHONDONTWRITEBYTECODE=1',
            'DEVICE_FILES_PATH='.sys_get_temp_dir().'/philcst-find',
            'DEVICE_RUNTIME_CONFIG_PATH='.DeviceFiles::runtimeConfigPath(),
            ...$arguments];
    } elseif (PHP_OS_FAMILY === 'Windows') {
        $this->line('Tip: run this in an Administrator window (with Npcap installed) to also hear readers on another network.');
    }

    $process = new Process($arguments, PythonLauncher::directory(), $environment, null, 900);
    if (Process::isTtySupported()) {
        $process->setTty(true);
    }

    return $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));
})->purpose('Find the UHF reader by plugging it in (before/after scan, passive listening, port scan)');

Artisan::command('devices:start', function (DeviceServiceRuntime $runtime) {
    Cache::forget('device-service-launch');
    $runtime->ensureRunning();
    sleep(3);
    $status = $runtime->readStatus();
    $this->info($status['message'] ?? '');
    $this->line('Device service running: '.(($status['service_running'] ?? false) ? 'yes' : 'no'));
})->purpose('Start the plug-and-detect device service in the background');

// Phase 3: move guest visits past valid_until to "overstay".
Artisan::command('guests:mark-overstay', function (GuestPassService $guestPassService) {
    $this->info('Visits marked overstay: '.$guestPassService->markOverstays());
})->purpose('Mark guest pass visits that passed their valid-until time as overstay');

Schedule::command('guests:mark-overstay')->everyMinute();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
