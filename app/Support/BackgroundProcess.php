<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Live view work: start a bundled program (go2rtc) in the background on
 * macOS/Linux and Windows, remember its PID, and stop it again.
 */
final class BackgroundProcess
{
    /**
     * @param  list<string>  $arguments
     */
    public static function launch(string $executable, array $arguments, string $workingDirectory, string $logPath, string $pidPath): bool
    {
        File::ensureDirectoryExists(dirname($logPath));
        File::ensureDirectoryExists(dirname($pidPath));
        PythonLauncher::rotateLog($logPath);

        $args = implode(' ', array_map('escapeshellarg', $arguments));

        if (PHP_OS_FAMILY === 'Windows') {
            // Start-Process gives the PID; the window stays hidden.
            $list = implode(',', array_map(fn (string $argument): string => "'".str_replace("'", "''", $argument)."'", $arguments));
            $script = sprintf(
                "(Start-Process -FilePath '%s' -ArgumentList %s -WorkingDirectory '%s' -WindowStyle Hidden -PassThru -RedirectStandardOutput '%s' -RedirectStandardError '%s').Id",
                str_replace("'", "''", $executable), $list ?: "''", str_replace("'", "''", $workingDirectory),
                str_replace("'", "''", $logPath.'.out'), str_replace("'", "''", $logPath)
            );
            $output = shell_exec('powershell -NoProfile -NonInteractive -Command '.escapeshellarg($script));
        } else {
            // Close inherited descriptors (see PythonLauncher) and print the PID.
            // "cd || exit;" (not "cd &&"): with "&&" the whole list ran in a
            // background subshell that kept PHP's output pipe open, and $!
            // was that subshell, not the program.
            $command = 'exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-; cd '.escapeshellarg($workingDirectory).' || exit 1; '
                .'nohup '.escapeshellarg($executable).' '.$args.' >> '.escapeshellarg($logPath).' 2>&1 < /dev/null & echo $!';
            $output = shell_exec('/bin/sh -c '.escapeshellarg($command));
        }

        $pid = (int) trim((string) $output);
        if ($pid > 0) {
            File::put($pidPath, (string) $pid);
        }

        return $pid > 0;
    }

    /**
     * CPU use of the program (percent of one core), null when unknown (or on
     * Windows, where it is not measured).
     */
    public static function cpuPercent(string $pidPath): ?float
    {
        $pid = is_file($pidPath) ? (int) trim((string) File::get($pidPath)) : 0;
        if ($pid <= 0 || PHP_OS_FAMILY === 'Windows') {
            return null;
        }

        $output = trim((string) @shell_exec('ps -o %cpu= -p '.$pid.' 2>/dev/null'));

        return is_numeric($output) ? round((float) $output, 1) : null;
    }

    public static function stop(string $pidPath): void
    {
        $pid = is_file($pidPath) ? (int) trim((string) File::get($pidPath)) : 0;
        if ($pid <= 0) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            @shell_exec('taskkill /PID '.$pid.' /F');
        } elseif (function_exists('posix_kill')) {
            @posix_kill($pid, 15);
        } else {
            @shell_exec('kill '.$pid);
        }
        File::delete($pidPath);
    }
}
