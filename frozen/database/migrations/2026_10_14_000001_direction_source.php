<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gate direction work, Phase A: every record says where its IN / OUT came
 * from and how sure it is.
 *
 * - direction_source: camera (moving away from / toward the camera), state
 *   (a registered vehicle's Inside / Outside when the camera could not
 *   tell, or had no camera), manual (set or entered by staff); null while
 *   the direction is unknown.
 * - direction_confidence: high / medium for a camera direction; null for
 *   state and manual.
 *
 * Existing records get theirs from what is already saved: staff
 * corrections, the RFID read's fusion status, and the crossing's details.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['vehicle_events', 'visitor_records'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('direction_source', 10)->nullable();
                $table->string('direction_confidence', 10)->nullable();
            });
        }

        $crossingConfidence = function (?int $crossingId = null, ?string $eventKey = null): ?string {
            $crossing = DB::table('vehicle_crossings')
                ->when($crossingId !== null, fn ($query) => $query->where('id', $crossingId), fn ($query) => $query->where('external_event_key', $eventKey))
                ->value('detection_metadata_json');
            $confidence = data_get(is_string($crossing) ? json_decode($crossing, true) : null, 'direction_confidence');

            return in_array($confidence, ['high', 'medium'], true) ? $confidence : null;
        };
        $correctedEvents = DB::table('direction_corrections')->whereNotNull('vehicle_event_id')->pluck('vehicle_event_id')->flip();
        $correctedRecords = DB::table('direction_corrections')->whereNotNull('visitor_record_id')->pluck('visitor_record_id')->flip();

        DB::table('vehicle_events')->whereIn('event_type', ['ENTRY', 'EXIT'])->orderBy('id')
            ->get(['id', 'event_origin', 'rfid_scan_log_id', 'external_event_key'])
            ->each(function (object $event) use ($correctedEvents, $crossingConfidence): void {
                $scan = $event->rfid_scan_log_id
                    ? DB::table('rfid_scan_logs')->where('id', $event->rfid_scan_log_id)->first(['fusion_status', 'vehicle_crossing_id'])
                    : null;
                $source = match (true) {
                    $correctedEvents->has($event->id) || ($scan?->fusion_status === 'set_by_staff') => 'manual',
                    $scan?->fusion_status === 'toggle' => 'state',
                    in_array($event->event_origin, ['manual', 'guest_manual'], true) => 'manual',
                    str_starts_with((string) $event->event_origin, 'rfid') && ! $scan?->vehicle_crossing_id => 'state',
                    default => 'camera',
                };
                $confidence = $source === 'camera'
                    ? $crossingConfidence($scan?->vehicle_crossing_id, $scan?->vehicle_crossing_id ? null : $event->external_event_key)
                    : null;

                DB::table('vehicle_events')->where('id', $event->id)->update(['direction_source' => $source, 'direction_confidence' => $confidence]);
            });

        DB::table('visitor_records')->whereIn('direction', ['IN', 'OUT'])->orderBy('id')
            ->get(['id', 'source', 'vehicle_crossing_id', 'external_event_key'])
            ->each(function (object $record) use ($correctedRecords, $crossingConfidence): void {
                $source = $correctedRecords->has($record->id) || $record->source === 'manual' ? 'manual' : 'camera';
                $confidence = $source === 'camera'
                    ? $crossingConfidence($record->vehicle_crossing_id, $record->vehicle_crossing_id ? null : $record->external_event_key)
                    : null;

                DB::table('visitor_records')->where('id', $record->id)->update(['direction_source' => $source, 'direction_confidence' => $confidence]);
            });
    }

    public function down(): void
    {
        foreach (['vehicle_events', 'visitor_records'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn(['direction_source', 'direction_confidence']);
            });
        }
    }
};
