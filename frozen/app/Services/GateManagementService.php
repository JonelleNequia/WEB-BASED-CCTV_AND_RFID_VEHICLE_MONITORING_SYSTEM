<?php

namespace App\Services;

use App\Models\DeviceAssignment;
use App\Models\Gate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GateManagementService
{
    /** Acquire the write lock BEFORE reading the count (also on SQLite). */
    public function synchronized(callable $work): mixed
    {
        return DB::transaction(function () use ($work) {
            DB::table('gate_management_locks')->where('id', 1)->increment('version');

            return $work();
        }, 5);
    }

    /** Safe on an existing installation; never reuses an archived code/ID. */
    public function ensureMinimum(): void
    {
        $this->synchronized(function (): void {
            while (Gate::query()->retained()->count() < 2) {
                $this->insertGate(null, false);
            }
        });
    }

    public function create(?string $name): Gate
    {
        return $this->synchronized(fn () => $this->insertGate($name, true));
    }

    protected function insertGate(?string $name, bool $active): Gate
    {
        $code = Gate::nextCode();
        $name = trim((string) $name) ?: 'Gate '.substr($code, 5);

        return Gate::query()->create([
            'code' => $code, 'name' => $name,
            'sort_order' => ((int) Gate::query()->max('sort_order')) + 1,
            'is_active' => $active, 'reader_type' => 'uhf_ethernet',
            'reader_name' => $name.' UHF Reader',
        ]);
    }

    public function archive(string $code): Gate
    {
        $gate = $this->synchronized(function () use ($code): Gate {
            $gate = Gate::query()->retained()->where('code', $code)->firstOrFail();
            if (Gate::query()->retained()->count() <= 2) {
                throw ValidationException::withMessages(['gate' => 'At least two gates are required.']);
            }

            // Keep camera IDs, calibration, credentials and evidence for history.
            // Deleting assignments directly avoids the normal unassign action's
            // conversion to a still-running manual camera source.
            DeviceAssignment::query()->where('station', $code)->delete();
            $gate->forceFill(['archived_at' => now(), 'is_active' => false])->save();

            return $gate;
        });

        Cache::forget('rfid-read-buffer.'.$code);
        Cache::forget('live-codecs.'.$code);
        foreach (['webrtc', 'hls', 'mjpeg'] as $mode) {
            Cache::forget("live-view-stats.$code.$mode");
        }
        Cache::forget('detector-runtime-heartbeat');
        // A just-saved setting may have consumed the two-second sync throttle.
        Cache::forget('go2rtc-config-sync');
        try {
            $this->refreshRuntime();
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'gate' => $gate->name.' was archived and its device assignments released, but runtime settings could not be refreshed. Save gate settings on Gates to retry the refresh.',
            ]);
        }

        return $gate;
    }

    public function refreshRuntime(): void
    {
        app(SettingsService::class)->exportCameraRuntimeConfig();
        app(DeviceRegistryService::class)->exportRuntimeConfig();
    }
}
