<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Starts a Python script from school-vehicle-monitoring-detector in the
 * background (macOS/Linux and Windows) and keeps its log file small.
 * Shared by the detector and the plug-and-detect device service.
 */
final class PythonLauncher
{
    public static function directory(): string
    {
        return base_path('school-vehicle-monitoring-detector');
    }

    public static function launch(string $script, string $logPath): bool
    {
        $scriptPath = self::directory().DIRECTORY_SEPARATOR.$script;

        if (! File::exists($scriptPath)) {
            return false;
        }

        File::ensureDirectoryExists(dirname($logPath));
        self::rotateLog($logPath);

        $workingDirectory = self::directory();
        $pythonExecutable = self::pythonExecutable();

        $command = match (PHP_OS_FAMILY) {
            'Windows' => 'cd /d '.escapeshellarg($workingDirectory)
                .' && start "" /B '.escapeshellarg($pythonExecutable)
                .' '.escapeshellarg($scriptPath)
                .' >> '.escapeshellarg($logPath).' 2>&1',
            // Phase 1: close inherited descriptors 3-9 first. Without this the
            // detector inherited the PHP dev server's listening socket and kept
            // the web port busy, so "php artisan serve" moved to another port
            // and requests to the old one hung.
            default => 'exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-; cd '.escapeshellarg($workingDirectory)
                .' && nohup '.escapeshellarg($pythonExecutable)
                .' '.escapeshellarg($scriptPath)
                .' >> '.escapeshellarg($logPath).' 2>&1 &',
        };

        $shellCommand = PHP_OS_FAMILY === 'Windows'
            ? 'cmd /c '.$command
            : '/bin/sh -lc '.escapeshellarg($command);

        $process = @proc_open($shellCommand, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($process)) {
            return false;
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        proc_close($process);

        return true;
    }

    public static function pythonExecutable(): string
    {
        $candidates = array_filter([
            // Windows install kit: the bundled Python next to the app (or DETECTOR_PYTHON).
            config('monitoring.python'),
            dirname(base_path()).'/runtime/python/python.exe',
            self::directory().'/.venv/bin/python',
            self::directory().'/.venv/Scripts/python.exe',
        ]);

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }

    /**
     * Copy the log to "<name>.1" and empty it once it passes the size limit.
     * Truncating in place works while the Python process keeps appending.
     */
    public static function rotateLog(string $path): void
    {
        // Windows install kit: the service manager (NSSM) rotates the logs it writes.
        if (config('monitoring.services.managed')) {
            return;
        }

        $limit = (int) config('monitoring.runtime_log_max_bytes', 5 * 1024 * 1024);

        try {
            if ($limit <= 0 || ! is_file($path) || filesize($path) < $limit) {
                return;
            }

            @copy($path, $path.'.1');
            $handle = @fopen($path, 'r+');

            if ($handle !== false) {
                ftruncate($handle, 0);
                fclose($handle);
            }
        } catch (Throwable) {
            // Never block a launch because of log housekeeping.
        }
    }
}
