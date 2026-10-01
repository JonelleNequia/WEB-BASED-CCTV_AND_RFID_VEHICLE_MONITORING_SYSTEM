<?php

namespace App\Http\Controllers;

use App\Models\Gate;
use App\Services\DetectorRuntimeService;
use App\Support\CameraFiles;
use App\Support\CameraSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 6: camera frames and detector status behind authentication
 * (they used to be plain files under public/camera).
 */
class CameraFileController extends Controller
{
    /**
     * Latest raw or AI-annotated frame for one camera (any signed-in user).
     */
    public function frame(string $role, string $kind = 'latest'): BinaryFileResponse
    {
        // Phase 1: a gate code (or the old "entrance" / "exit").
        $role = Gate::resolveCode($role) ?? abort(404);
        $path = CameraFiles::framePath($role, $kind);

        abort_unless(File::isFile($path) && File::size($path) > 0, 404);

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /**
     * Detector status for admins, with camera passwords removed.
     */
    public function status(DetectorRuntimeService $detectorRuntimeService): JsonResponse
    {
        $status = $detectorRuntimeService->readStatus();

        foreach ($status['cameras'] ?? [] as $role => $camera) {
            if (is_array($camera) && is_string($camera['source_value'] ?? null)) {
                $status['cameras'][$role]['source_value'] = self::withoutCredentials($camera['source_value']);
            }
        }

        return response()->json($status)->header('Cache-Control', 'no-store, max-age=0');
    }

    public static function withoutCredentials(string $url): string
    {
        return CameraSource::withoutCredentials($url);
    }
}
