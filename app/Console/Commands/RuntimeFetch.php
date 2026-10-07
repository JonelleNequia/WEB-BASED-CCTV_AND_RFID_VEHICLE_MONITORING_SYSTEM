<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Windows install kit, Phase 2: big binaries are not in git. For development
 * this downloads what the kit bundles for the detector and the live view:
 *
 *   php artisan runtime:fetch          models + go2rtc for this computer
 *   php artisan runtime:fetch --all    also the go2rtc builds for the other systems
 *
 * Every file is checked against build/windows/runtimes.json (SHA-256);
 * a file already present and correct is kept.
 */
class RuntimeFetch extends Command
{
    protected $signature = 'runtime:fetch {--all : Also the go2rtc builds for other systems}';

    protected $description = 'Download the detector models and go2rtc (checked by SHA-256) for development';

    public function handle(): int
    {
        $manifest = json_decode((string) File::get(base_path('build/windows/runtimes.json')), true);
        $entries = (array) $manifest['models'];

        foreach ((array) $manifest['development'] as $name => $entry) {
            if ($this->option('all') || $this->forThisComputer($name)) {
                $entries[$name] = $entry;
            }
        }

        $failed = 0;
        foreach ($entries as $name => $entry) {
            try {
                $this->line(sprintf('%-18s %s', $name, $this->fetch($name, $entry)));
            } catch (Throwable $exception) {
                $failed++;
                $this->error(sprintf('%-18s %s', $name, $exception->getMessage()));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function forThisComputer(string $name): bool
    {
        $arm = in_array(strtolower(php_uname('m')), ['arm64', 'aarch64'], true);

        return match (PHP_OS_FAMILY) {
            'Darwin' => $name === ($arm ? 'go2rtc_mac_arm64' : 'go2rtc_mac_amd64'),
            'Windows' => $name === 'go2rtc_win64',
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function fetch(string $name, array $entry): string
    {
        $target = base_path((string) $entry['target']);
        if ($entry['type'] === 'file' && is_file($target) && hash_file('sha256', $target) === $entry['sha256']) {
            return 'already here';
        }

        $download = storage_path('app/runtime-fetch/'.basename(parse_url($entry['urls'][0], PHP_URL_PATH)));
        File::ensureDirectoryExists(dirname($download));
        if (! is_file($download) || hash_file('sha256', $download) !== $entry['sha256']) {
            $response = Http::timeout(600)->withOptions(['sink' => $download])->get($entry['urls'][0]);
            if (! $response->successful()) {
                throw new RuntimeException('download failed (HTTP '.$response->status().')');
            }
        }

        $hash = hash_file('sha256', $download);
        if ($hash !== $entry['sha256']) {
            File::delete($download);
            throw new RuntimeException("SHA-256 $hash does not match runtimes.json; not used");
        }

        File::ensureDirectoryExists($entry['type'] === 'zip' ? $target : dirname($target));
        if ($entry['type'] === 'zip') {
            $zip = new ZipArchive;
            if ($zip->open($download) !== true || ! $zip->extractTo($target)) {
                throw new RuntimeException('could not unpack');
            }
            $zip->close();
        } else {
            File::copy($download, $target);
        }
        File::delete($download);

        return 'downloaded and checked';
    }
}
