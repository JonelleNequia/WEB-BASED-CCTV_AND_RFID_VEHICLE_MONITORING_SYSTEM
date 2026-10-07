<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\Gate;
use App\Models\NetworkDevice;
use App\Models\RfidScanLog;
use App\Models\Roi;
use App\Models\User;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Support\CameraFiles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Delete device work: "Delete device" on a camera or reader (Settings ›
 * Gates, Advanced › All network devices).
 *
 * Only the hardware's own data goes: the device record and its gate
 * assignment; for a camera its login, stream settings, the gate's
 * calibration, cached frames and status; for a reader its connection
 * settings, read buffer and diagnostics. The IN/OUT, visitor and tag-read
 * records stay and say "… (removed)". The runtime files are written at once,
 * so the detector and the reader link stop using the device (no restart).
 */
class DeviceDeletionService
{
    public function __construct(protected DeviceRegistryService $registry)
    {
    }

    /**
     * What the confirmation shows.
     *
     * @return array{id: int, name: string, kind: string, gates: list<array{code: string, name: string, role: string}>, removes: list<string>, records_kept: int}
     */
    public function summary(NetworkDevice $device): array
    {
        $ids = $this->deviceIds($device);
        $assignments = DeviceAssignment::query()->whereIn('network_device_id', $ids)->get();
        $camera = $device->kind === NetworkDevice::KIND_CAMERA || $assignments->contains('role', DeviceAssignment::ROLE_CAMERA);
        $reader = $device->kind === NetworkDevice::KIND_READER || $assignments->contains('role', DeviceAssignment::ROLE_READER);

        $removes = ['The device and its place at the gate'];
        if ($camera) {
            array_push($removes, 'The saved camera login and video settings', "The gate's detection zone, trigger line and IN direction", 'The last picture and the status of this camera');
        }
        if ($reader) {
            array_push($removes, 'The reader connection settings and the temporary workaround address', 'The tags waiting in its memory and its diagnostics');
        }

        return [
            'id' => $device->id,
            'name' => $this->registry->friendlyName($device),
            'kind' => $camera ? 'camera' : ($reader ? 'reader' : 'device'),
            'gates' => $assignments->map(fn (DeviceAssignment $assignment): array => [
                'code' => $assignment->station, 'name' => Gate::labelFor($assignment->station), 'role' => $assignment->role,
            ])->values()->all(),
            'removes' => $removes,
            'records_kept' => $this->recordQueries($ids)->sum(fn ($query): int => $query->count()),
        ];
    }

    /**
     * @return array{message: string, records_kept: int, gates: list<string>}
     */
    public function delete(NetworkDevice $device, ?User $user = null): array
    {
        $summary = $this->summary($device);
        $ids = $this->deviceIds($device);
        $assignments = DeviceAssignment::query()->whereIn('network_device_id', $ids)->get();

        DB::transaction(function () use ($ids, $assignments, $device, $summary, $user): void {
            $this->labelRecordsRemoved($ids);

            foreach ($assignments as $assignment) {
                $assignment->role === DeviceAssignment::ROLE_CAMERA
                    ? $this->forgetCamera($assignment->station)
                    : $this->forgetReader($assignment->station);
            }

            DeviceAssignment::query()->whereIn('network_device_id', $ids)->delete();
            NetworkDevice::query()->whereIn('id', $ids)->delete();

            DB::table('device_removals')->insert([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'device_kind' => $summary['kind'],
                'device_name' => mb_substr($summary['name'], 0, 150),
                'mac' => $device->mac,
                'ip' => $device->ip,
                'gates' => json_encode(array_column($summary['gates'], 'code')),
                'removed' => json_encode($summary['removes']),
                'records_kept' => $summary['records_kept'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        foreach ($assignments as $assignment) {
            if ($assignment->role === DeviceAssignment::ROLE_CAMERA) {
                $this->deleteCameraFiles($assignment->station);
            }
        }

        // The detector, go2rtc and the reader link follow the new files by themselves.
        $this->registry->syncAssignments(force: true);

        $gates = array_column($summary['gates'], 'name');

        return [
            'message' => $summary['name'].' deleted'.($gates ? ' from '.implode(' and ', $gates) : '').'. Its records are kept.',
            'records_kept' => $summary['records_kept'],
            'gates' => array_column($summary['gates'], 'code'),
        ];
    }

    public function hide(NetworkDevice $device): void
    {
        abort_if(DeviceAssignment::query()->whereIn('network_device_id', $this->deviceIds($device))->exists(), 422,
            'This device is used by a gate. Delete it or remove it from the gate first.');

        NetworkDevice::query()->whereIn('id', $this->deviceIds($device))->update(['hidden_at' => now()]);
    }

    public function unhide(NetworkDevice $device): void
    {
        NetworkDevice::query()->whereIn('id', $this->deviceIds($device))->update(['hidden_at' => null]);
    }

    /**
     * The device and the duplicate row the scan may keep for its address
     * without a MAC (a device on another subnet).
     *
     * @return list<int>
     */
    protected function deviceIds(NetworkDevice $device): array
    {
        $ids = [$device->id];
        if (filled($device->mac) && filled($device->ip)) {
            $ids = [...$ids, ...NetworkDevice::query()->whereNull('mac')->where('device_key', 'ip:'.$device->ip)
                ->where('kind', $device->kind)->pluck('id')->all()];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Builder>
     */
    protected function recordQueries(array $ids)
    {
        return collect([
            VehicleCrossing::query()->whereIn('camera_device_id', $ids),
            VisitorRecord::query()->whereIn('camera_device_id', $ids),
            VehicleEvent::query()->whereIn('camera_device_id', $ids),
            RfidScanLog::query()->whereIn('reader_device_id', $ids),
        ]);
    }

    /**
     * Records keep the device's name with "(removed)"; the link to the
     * deleted device row is cleared.
     *
     * @param  list<int>  $ids
     */
    protected function labelRecordsRemoved(array $ids): void
    {
        $suffix = VehicleCrossing::REMOVED_SUFFIX;

        foreach ([VehicleCrossing::class, VisitorRecord::class, VehicleEvent::class] as $model) {
            $rows = $model::query()->whereIn('camera_device_id', $ids);
            foreach ((clone $rows)->distinct()->pluck('camera_device_label') as $label) {
                $named = $label ?: 'Camera';
                (clone $rows)->where(fn ($query) => $label === null ? $query->whereNull('camera_device_label') : $query->where('camera_device_label', $label))
                    ->update(['camera_device_label' => str_ends_with($named, $suffix) ? $named : $named.$suffix, 'camera_device_id' => null]);
            }
        }

        $scans = RfidScanLog::query()->whereIn('reader_device_id', $ids);
        foreach ((clone $scans)->distinct()->pluck('reader_name') as $name) {
            $named = $name ?: 'UHF Reader';
            (clone $scans)->where(fn ($query) => $name === null ? $query->whereNull('reader_name') : $query->where('reader_name', $name))
                ->update(['reader_name' => mb_substr(str_ends_with($named, $suffix) ? $named : $named.$suffix, 0, 100), 'reader_device_id' => null]);
        }
    }

    /** The camera's login, stream settings and the gate's calibration. */
    protected function forgetCamera(string $gate): void
    {
        $camera = Camera::query()->forRole($gate)->first();
        if ($camera) {
            $camera->forceFill([
                'source_type' => Camera::SOURCE_NONE,
                'source_value' => '',
                'snapshot_source_value' => null,
                'source_username' => null,
                'source_password' => null,
                'calibration_mask_json' => null,
                'calibration_line_json' => null,
                'last_connection_status' => 'unknown',
                'last_connection_message' => null,
                'last_connected_at' => null,
            ])->save();
            Roi::query()->where('camera_id', $camera->id)->delete();
        }

        Cache::forget("live-codecs.$gate");
        foreach (['webrtc', 'hls', 'mjpeg'] as $mode) {
            Cache::forget("live-view-stats.$gate.$mode");
        }
    }

    /** The last frames and snapshots of the gate's camera (after the records are saved). */
    protected function deleteCameraFiles(string $gate): void
    {
        foreach (CameraFiles::KINDS as $kind) {
            File::delete(CameraFiles::framePath($gate, $kind));
        }

        $snapshots = CameraFiles::path('snapshots');
        if (File::isDirectory($snapshots)) {
            foreach (File::files($snapshots) as $file) {
                if (str_starts_with($file->getFilename(), $gate.'_') || str_starts_with($file->getFilename(), $gate.'-')) {
                    File::delete($file->getPathname());
                }
            }
        }
    }

    /** The reader's diagnostics and the reads Laravel keeps for its gate. */
    protected function forgetReader(string $gate): void
    {
        Cache::forget('rfid-read-buffer.'.$gate);
        foreach (['attached', 'unknown_offline', 'rfid_only'] as $counter) {
            Cache::forget('rfid-diag.'.now()->toDateString().'.'.$gate.'.'.$counter);
        }
    }
}
