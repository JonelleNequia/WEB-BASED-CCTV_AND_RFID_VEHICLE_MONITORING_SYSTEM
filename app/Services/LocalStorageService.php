<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalStorageService
{
    /**
     * Ensure required local media and archive folders exist.
     */
    public function ensureBaseDirectories(): void
    {
        foreach (['vehicle_images', 'plate_images', 'detected_vehicle_images'] as $key) {
            Storage::disk($this->mediaDisk())->makeDirectory($this->mediaDirectory($key));
        }

        foreach (['rfid_exports', 'backups'] as $key) {
            Storage::disk($this->archiveDisk())->makeDirectory($this->archiveDirectory($key));
        }
    }

    /**
     * Store a browser-visible upload on the public local disk.
     */
    public function storeEventImage(UploadedFile $file, string $type): string
    {
        $directory = match ($type) {
            'vehicle' => $this->mediaDirectory('vehicle_images'),
            'plate' => $this->mediaDirectory('plate_images'),
            default => $this->mediaDirectory('vehicle_images'),
        };

        return $file->store($directory, $this->mediaDisk());
    }

    /**
     * Store one RFID payload export on the private local disk for offline traceability.
     */
    public function storeRfidPayload(array $payload, string $scanLocation): string
    {
        $timestamp = Carbon::now()->format('Ymd_His_u');
        $directory = trim($this->archiveDirectory('rfid_exports').'/'.$scanLocation, '/');
        $filename = 'scan_'.$timestamp.'_'.Str::lower(Str::random(6)).'.json';
        $path = $directory.'/'.$filename;

        Storage::disk($this->archiveDisk())->makeDirectory($directory);
        Storage::disk($this->archiveDisk())->put(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $path;
    }

    /**
     * Copy the latest public camera frame into public evidence storage.
     */
    public function storeLatestCameraSnapshot(string $cameraRole): ?string
    {
        $sourcePath = public_path('camera/'.$cameraRole.'_latest_frame.jpg');

        if (! File::exists($sourcePath)) {
            return null;
        }

        $directory = $this->mediaDirectory('vehicle_images').'/guest-rfid';
        $filename = 'guest_'.$cameraRole.'_'.Carbon::now()->format('Ymd_His_u').'_'.Str::lower(Str::random(6)).'.jpg';
        $relativePath = trim($directory.'/'.$filename, '/');
        $destinationPath = Storage::disk($this->mediaDisk())->path($relativePath);

        File::ensureDirectoryExists(dirname($destinationPath));
        File::copy($sourcePath, $destinationPath);

        return $relativePath;
    }

    /**
     * Get a small summary of local-only storage paths for settings and documentation.
     *
     * @return array<string, string>
     */
    public function storageSummary(): array
    {
        $this->ensureBaseDirectories();

        return [
            'media_disk' => $this->mediaDisk(),
            'media_root' => $this->diskRootPath($this->mediaDisk()),
            'vehicle_images' => $this->diskPath($this->mediaDisk(), $this->mediaDirectory('vehicle_images')),
            'plate_images' => $this->diskPath($this->mediaDisk(), $this->mediaDirectory('plate_images')),
            'detected_vehicle_images' => $this->diskPath($this->mediaDisk(), $this->mediaDirectory('detected_vehicle_images')),
            'archive_disk' => $this->archiveDisk(),
            'rfid_exports' => $this->diskPath($this->archiveDisk(), $this->archiveDirectory('rfid_exports')),
            'backups' => $this->diskPath($this->archiveDisk(), $this->archiveDirectory('backups')),
        ];
    }

    /**
     * Resolve the configured media disk.
     */
    public function mediaDisk(): string
    {
        return (string) config('monitoring.media_disk', 'public');
    }

    /**
     * Resolve the configured archive disk.
     */
    public function archiveDisk(): string
    {
        return (string) config('monitoring.archive_disk', 'local');
    }

    /**
     * Resolve a media directory key from config.
     */
    public function mediaDirectory(string $key): string
    {
        return trim((string) config("monitoring.media_directories.$key"), '/');
    }

    /**
     * Resolve an archive directory key from config.
     */
    public function archiveDirectory(string $key): string
    {
        return trim((string) config("monitoring.archive_directories.$key"), '/');
    }

    /**
     * Resolve an absolute path on one configured disk.
     */
    protected function diskPath(string $disk, string $path): string
    {
        return Storage::disk($disk)->path($path);
    }

    /**
     * Resolve the root path of one configured disk.
     */
    protected function diskRootPath(string $disk): string
    {
        $root = Storage::disk($disk)->path('/');

        return File::isDirectory($root) ? $root : dirname($root);
    }
}
