<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delete device work:
 * - every IN/OUT, visitor and tag-read record remembers the camera or reader
 *   that made it (id + name), so it still says "Gate 1 Camera (removed)"
 *   after the device is deleted (records are never deleted with a device);
 * - a hidden device stays out of the device list even when scanned again;
 * - device_removals logs who deleted which device and when.
 *
 * Plain indexed columns (no foreign keys): the device service clears them
 * when a device is deleted, the same on SQLite and MySQL.
 */
return new class extends Migration
{
    protected const CAMERA_TABLES = ['vehicle_crossings', 'visitor_records', 'vehicle_events'];

    public function up(): void
    {
        Schema::table('network_devices', function (Blueprint $table): void {
            $table->timestamp('hidden_at')->nullable()->after('is_new');
        });

        foreach (self::CAMERA_TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('camera_device_id')->nullable()->index();
                $table->string('camera_device_label', 150)->nullable();
            });
        }

        Schema::table('rfid_scan_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('reader_device_id')->nullable()->index();
        });

        Schema::create('device_removals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('device_kind', 20);
            $table->string('device_name', 150);
            $table->string('mac', 17)->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('gates')->nullable();
            $table->json('removed')->nullable();
            $table->unsignedInteger('records_kept')->default(0);
            $table->timestamps();
        });

        $this->backfill();
    }

    /**
     * Records made at a gate since its current device was assigned belong to that device.
     */
    protected function backfill(): void
    {
        $assignments = DB::table('device_assignments')
            ->join('network_devices', 'network_devices.id', '=', 'device_assignments.network_device_id')
            ->get(['device_assignments.station', 'device_assignments.role', 'device_assignments.created_at', 'network_devices.id as device_id',
                'network_devices.name', 'network_devices.brand', 'network_devices.model']);

        foreach ($assignments as $assignment) {
            $since = $assignment->created_at;
            if ($assignment->role === 'reader') {
                DB::table('rfid_scan_logs')->where('scan_location', $assignment->station)->whereNull('reader_device_id')
                    ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
                    ->update(['reader_device_id' => $assignment->device_id]);

                continue;
            }

            $gateName = DB::table('gates')->where('code', $assignment->station)->value('name') ?? $assignment->station;
            $label = mb_substr($gateName.' Camera · '.trim(($assignment->model ?: $assignment->name ?: $assignment->brand) ?? 'Camera'), 0, 150);
            $cameraId = DB::table('cameras')->where('camera_role', $assignment->station)->value('id');
            foreach (['vehicle_crossings' => 'gate', 'visitor_records' => 'gate'] as $name => $gateColumn) {
                DB::table($name)->where($gateColumn, $assignment->station)->whereNull('camera_device_id')
                    ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
                    ->update(['camera_device_id' => $assignment->device_id, 'camera_device_label' => $label]);
            }
            if ($cameraId) {
                DB::table('vehicle_events')->where('camera_id', $cameraId)->whereNull('camera_device_id')
                    ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
                    ->update(['camera_device_id' => $assignment->device_id, 'camera_device_label' => $label]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_removals');
        Schema::table('rfid_scan_logs', function (Blueprint $table): void {
            $table->dropIndex(['reader_device_id']);
            $table->dropColumn('reader_device_id');
        });
        foreach (self::CAMERA_TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['camera_device_id']);
                $table->dropColumn(['camera_device_id', 'camera_device_label']);
            });
        }
        Schema::table('network_devices', fn (Blueprint $table) => $table->dropColumn('hidden_at'));
    }
};
