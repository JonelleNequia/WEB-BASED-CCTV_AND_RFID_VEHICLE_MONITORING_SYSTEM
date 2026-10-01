<?php

namespace App\Support;

/**
 * Phase 6: where the detector's runtime camera files live.
 *
 * Kept outside public/ so frames and camera status (which can include RTSP
 * addresses) are only reachable through signed-in routes.
 * The Python side uses the same layout (config.py CAMERA_FILES_DIR).
 */
final class CameraFiles
{
    public const KINDS = ['latest', 'annotated'];

    public static function directory(): string
    {
        $path = rtrim((string) config('monitoring.camera_files_path', storage_path('app/camera')), '/');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /**
     * Files shared with the Python detector live in the same folder, so tests
     * (CAMERA_FILES_PATH) never touch the running detector's files.
     */
    public static function path(string $name): string
    {
        return self::directory().'/'.$name;
    }

    public static function statusPath(): string
    {
        return self::directory().'/camera_status.json';
    }

    public static function framePath(string $role, string $kind = 'latest'): string
    {
        return self::directory().'/frames/'.$role.'_'.$kind.'_frame.jpg';
    }
}
