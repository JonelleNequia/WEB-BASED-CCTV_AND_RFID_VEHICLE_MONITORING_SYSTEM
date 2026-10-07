<?php

namespace App\Models\Concerns;

use App\Models\DeviceAssignment;
use App\Models\Gate;
use App\Models\NetworkDevice;
use App\Services\DeviceRegistryService;

/**
 * Delete device work: a new record remembers the camera (crossings, visitor
 * records, IN/OUT events) or reader (tag reads) assigned to its gate when it
 * was made. Deleting that device later keeps the record; its label then says
 * "(removed)".
 *
 * The model says which gate and role: sourceDeviceGate(), sourceDeviceRole().
 */
trait RecordsSourceDevice
{
    public const REMOVED_SUFFIX = ' (removed)';

    protected static function bootRecordsSourceDevice(): void
    {
        static::creating(function (self $model): void {
            $model->stampSourceDevice();
        });
    }

    abstract public function sourceDeviceGate(): ?string;

    abstract public function sourceDeviceRole(): string;

    public function stampSourceDevice(): void
    {
        $column = $this->sourceDeviceRole() === DeviceAssignment::ROLE_READER ? 'reader_device_id' : 'camera_device_id';
        $gate = $this->sourceDeviceGate();
        if (filled($this->getAttribute($column)) || blank($gate)) {
            return;
        }

        $device = DeviceAssignment::query()->with('device')
            ->where('station', $gate)->where('role', $this->sourceDeviceRole())->first()?->device;
        if (! $device) {
            return;
        }

        $this->setAttribute($column, $device->id);
        if ($column === 'camera_device_id' && blank($this->getAttribute('camera_device_label'))) {
            $this->setAttribute('camera_device_label', self::cameraLabel($gate, $device));
        }
    }

    /** "Gate 1 Camera · TP-Link VIGI-C240 Camera" (kept on the record). */
    public static function cameraLabel(string $gate, NetworkDevice $device): string
    {
        $name = preg_replace('/\s+Camera$/i', '', app(DeviceRegistryService::class)->friendlyName($device)) ?: 'Camera';

        return mb_substr(Gate::labelFor($gate).' Camera · '.$name, 0, 150 - strlen(self::REMOVED_SUFFIX));
    }
}
