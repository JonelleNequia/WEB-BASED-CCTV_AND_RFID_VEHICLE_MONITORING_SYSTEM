<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Camera source work (Phase 1):
 * - an assigned camera's source is the device (its MAC): the stored URL is
 *   dropped and its main / sub paths are kept on the assignment, so the URL
 *   is built from the camera's current address;
 * - USB webcams are not supported any more: such a gate has no camera.
 * A hand-typed RTSP / URL source stays as it is ("manual at first"); the gate
 * card offers to switch it to automatic when the scan found that camera.
 */
return new class extends Migration
{
    public function up(): void
    {
        $assignments = DB::table('device_assignments')->where('role', 'camera')->get();

        foreach ($assignments as $assignment) {
            $options = json_decode((string) $assignment->options, true) ?: [];
            $paths = (array) ($options['paths'] ?? []);
            $paths['main'] ??= $options['snapshot_path'] ?? ($options['path'] ?? null);
            $paths['sub'] ??= ($options['stream'] ?? 'sub') === 'sub' ? ($options['path'] ?? $paths['main']) : $paths['main'];
            $options['paths'] = array_filter($paths);
            $options['paths_from'] ??= 'brand';

            DB::table('device_assignments')->where('id', $assignment->id)->update(['options' => json_encode($options)]);
            DB::table('cameras')->where('camera_role', $assignment->station)
                ->update(['source_type' => 'device', 'source_value' => '', 'snapshot_source_value' => null]);
        }

        DB::table('cameras')->where('source_type', 'webcam')->update(['source_type' => 'none', 'source_value' => '']);
    }

    public function down(): void
    {
        // The URLs are rebuilt from the assignments on the next export; nothing to restore.
        DB::table('cameras')->where('source_type', 'device')->update(['source_type' => 'none']);
    }
};
