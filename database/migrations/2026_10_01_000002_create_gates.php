<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 (visitor model): gates instead of Entrance/Exit stations.
 *
 * Both gates record IN and OUT. Each gate has its own camera (cameras.camera_role
 * = gate code), reader (assignment or manual address) and calibration.
 *
 * Existing data: Entrance -> gate-1, Exit -> gate-2 in every table that stored
 * the station, and the entrance_* / exit_* settings move into the gate rows.
 * Nothing is deleted.
 */
return new class extends Migration
{
    /** Old station value => new gate code. */
    protected const MAP = ['entrance' => 'gate-1', 'exit' => 'gate-2'];

    /** Tables and columns that stored the station. */
    protected const COLUMNS = [
        ['cameras', 'camera_role'],
        ['rfid_scan_logs', 'scan_location'],
        ['guest_vehicle_observations', 'location'],
        ['device_assignments', 'station'],
    ];

    public function up(): void
    {
        Schema::create('gates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            // Reader: picked in Settings › Devices; the manual address is an override.
            $table->string('reader_type', 30)->default('nfc');
            $table->string('reader_name', 100)->nullable();
            $table->boolean('reader_manual')->default(false);
            $table->string('reader_ip', 64)->nullable();
            $table->unsignedInteger('reader_port')->nullable();
            $table->string('reader_transport', 10)->default('tcp');
            $table->timestamps();
        });

        $settings = DB::table('system_settings')->pluck('setting_value', 'setting_key');
        $now = now();
        $order = 1;

        foreach (self::MAP as $station => $code) {
            $number = $order;
            $label = trim((string) ($settings["{$station}_portal_label"] ?? ''));
            // The old default names ("PHILCST Entrance Portal") become "Gate 1"; a custom name stays.
            $name = $label === '' || preg_match('/^PHILCST (Entrance|Exit) Portal$/i', $label) ? "Gate {$number}" : $label;

            DB::table('gates')->insert([
                'code' => $code,
                'name' => $name,
                'sort_order' => $order++,
                'is_active' => true,
                'reader_type' => (string) ($settings["{$station}_reader_type"] ?? 'nfc') ?: 'nfc',
                // "Entrance UHF Reader" -> "Gate 1 UHF Reader"; a custom reader name stays.
                'reader_name' => preg_replace('/^(Entrance|Exit)(\s+Station)?\b/i', $name, (string) ($settings["{$station}_rfid_reader_name"] ?? '')) ?: null,
                'reader_manual' => ($settings["{$station}_reader_manual"] ?? '0') === '1',
                'reader_ip' => filled($settings["{$station}_reader_ip"] ?? null) ? $settings["{$station}_reader_ip"] : null,
                'reader_port' => filled($settings["{$station}_reader_port"] ?? null) ? (int) $settings["{$station}_reader_port"] : null,
                'reader_transport' => (string) ($settings["{$station}_reader_transport"] ?? 'tcp') ?: 'tcp',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::COLUMNS as [$table, $column]) {
            foreach (self::MAP as $old => $new) {
                DB::table($table)->where($column, $old)->update([$column => $new]);
            }
        }

        // The default camera names follow the gate ("Gate 1 Camera"); custom names stay.
        foreach (DB::table('gates')->get(['code', 'name']) as $gate) {
            DB::table('cameras')->where('camera_role', $gate->code)
                ->whereIn('camera_name', ['PHILCST Entrance Camera', 'PHILCST Exit Camera', 'Entrance Camera', 'Exit Camera'])
                ->update(['camera_name' => $gate->name.' Camera']);
        }

        DB::table('system_settings')
            ->where(fn ($query) => $query->where('setting_key', 'like', 'entrance\_%')->orWhere('setting_key', 'like', 'exit\_%'))
            ->whereIn('setting_key', $this->oldSettingKeys())
            ->delete();
    }

    public function down(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            foreach (self::MAP as $old => $new) {
                DB::table($table)->where($column, $new)->update([$column => $old]);
            }
        }

        $now = now();
        foreach (DB::table('gates')->whereIn('code', self::MAP)->get() as $gate) {
            $station = array_search($gate->code, self::MAP, true);
            foreach ([
                'portal_label' => $gate->name,
                'rfid_reader_name' => $gate->reader_name,
                'reader_type' => $gate->reader_type,
                'reader_manual' => $gate->reader_manual ? '1' : '0',
                'reader_ip' => $gate->reader_ip,
                'reader_port' => $gate->reader_port,
                'reader_transport' => $gate->reader_transport,
            ] as $key => $value) {
                DB::table('system_settings')->updateOrInsert(
                    ['setting_key' => "{$station}_{$key}"],
                    ['setting_value' => (string) $value, 'created_at' => $now, 'updated_at' => $now]
                );
            }
        }

        Schema::dropIfExists('gates');
    }

    /**
     * @return list<string>
     */
    protected function oldSettingKeys(): array
    {
        $keys = [];
        foreach (array_keys(self::MAP) as $station) {
            foreach (['portal_label', 'rfid_reader_name', 'reader_type', 'reader_ip', 'reader_port', 'reader_manual', 'reader_transport'] as $suffix) {
                $keys[] = "{$station}_{$suffix}";
            }
        }

        return $keys;
    }
};
