<?php

namespace App\Services;

use App\Exceptions\OccurrenceConflict;
use App\Models\RfidScanLog;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Support\OccurrenceMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CrossingSnapshotService
{
    public const KEY = '_application_snapshot';

    // pass_worker has two existing stages of at most 600 seconds each.
    private const PASS_FRAME_SECONDS = 1200;

    /** Slow file writes complete before acquiring the business-data write lock. */
    public function prepare(?UploadedFile $image): array
    {
        if (! $image) {
            return ['path' => null, 'status' => 'unavailable'];
        }
        $dimensions = @getimagesize($image->getRealPath());
        if (! $image->isValid() || $image->getSize() > 10 * 1024 * 1024
            || ! in_array($image->getMimeType(), ['image/jpeg', 'image/png'], true)
            || ! $dimensions || $dimensions[0] > 8192 || $dimensions[1] > 8192) {
            return ['path' => null, 'status' => 'failed'];
        }
        $owned = false;
        $file = null;
        try {
            $sha = hash_file('sha256', $image->getRealPath());
            $disk = Storage::disk('occurrences');
            $output = null;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $file = Str::uuid().'.'.($image->getMimeType() === 'image/png' ? 'png' : 'jpg');
                $physical = $disk->path($file);
                \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($physical));
                $output = @fopen($physical, 'xb'); // Exclusive ownership: never overwrite another occurrence.
                if (is_resource($output)) {
                    $owned = true;
                    break;
                }
                if (! is_file($physical)) {
                    throw new \RuntimeException('Occurrence image destination unavailable.');
                }
            }
            if (! $owned) {
                throw new \RuntimeException('Could not allocate a unique occurrence image.');
            }
            $stream = fopen($image->getRealPath(), 'rb');
            try {
                $bytes = stream_copy_to_stream($stream, $output, 10 * 1024 * 1024 + 1);
                if ($bytes !== $image->getSize() || ! fflush($output) || ! fsync($output)) {
                    throw new \RuntimeException('Occurrence image write failed.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
            }

            app(DeliveryMediaJournal::class)->track(OccurrenceMedia::PREFIX.$file);
            return ['path' => OccurrenceMedia::PREFIX.$file, 'status' => 'available', 'sha256' => $sha,
                'uploaded_at' => now()->toIso8601String()];
        } catch (\Throwable $exception) {
            if (isset($output) && is_resource($output)) {
                fclose($output);
            }
            report($exception);
            // Only this operation's unique file may be incomplete.
            if ($owned) {
                try {
                    Storage::disk('occurrences')->delete($file);
                } catch (\Throwable) {
                }
            }

            return ['path' => null, 'status' => 'failed'];
        }
    }

    public function discard(array $upload): void
    {
        if ($file = OccurrenceMedia::privateFile($upload['path'] ?? null)) {
            try {
                Storage::disk('occurrences')->delete($file);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    /** Under PlateProcessingLock. Return whether this unique staged file was used. */
    public function attach(VehicleCrossing $crossing, array $upload, array $picture = []): bool
    {
        $oldPath = $crossing->snapshot_path;
        $metadata = (array) $crossing->detection_metadata_json;
        $previous = (array) ($metadata[self::KEY] ?? []);
        $accepted = false;
        if ($upload['path']) {
            $current = OccurrenceMedia::exists($oldPath);
            $score = isset($picture['score']) && is_numeric($picture['score']) ? max(0, (float) $picture['score']) : null;
            $selected = $picture['selected_at'] ?? null;
            if ($selected && (\Illuminate\Support\Carbon::parse($selected)->lt($crossing->crossed_at->copy()->subSeconds(2))
                || \Illuminate\Support\Carbon::parse($selected)->gt($crossing->crossed_at->copy()->addSeconds(self::PASS_FRAME_SECONDS)))) {
                throw new OccurrenceConflict('Frame-selection time is outside this pass window.');
            }
            // A legacy timestamp-free first image is allowed. Replacements need
            // ordered selection evidence and the detector's clearer-picture bound.
            $newer = $selected && ($previous['selected_at'] ?? null)
                && \Illuminate\Support\Carbon::parse($selected)->gt(\Illuminate\Support\Carbon::parse($previous['selected_at']));
            $clearer = $score !== null && isset($previous['score']) && $score >= max(.0001, (float) $previous['score']) * 1.2;
            if (! $current || (($previous['sha256'] ?? null) !== $upload['sha256'] && $newer && $clearer)) {
                $crossing->snapshot_path = $upload['path'];
                $metadata[self::KEY] = array_intersect_key($upload, array_flip(['status', 'sha256', 'uploaded_at']))
                    + ['selected_at' => $selected, 'captured_at' => null, 'score' => $score];
                $accepted = true;
            } elseif (($previous['sha256'] ?? null) !== $upload['sha256']) {
                throw new OccurrenceConflict('Image replacement is stale or lacks a bounded clearer-picture decision.');
            }
        } elseif (! OccurrenceMedia::exists($oldPath)) {
            $metadata[self::KEY] = ['status' => $upload['status'], 'captured_at' => null];
        }
        $crossing->forceFill(['detection_metadata_json' => $metadata])->save();
        $this->synchronize($crossing, $oldPath);

        return $accepted;
    }

    /** Follow authoritative relationships only; never search by plate/latest/key alone. */
    public function synchronize(VehicleCrossing $crossing, ?string $previousPath = null): void
    {
        $context = ['external_event_key' => $crossing->external_event_key, 'camera_role' => $crossing->gate,
            'camera_id' => $crossing->camera_id, 'event_time' => $crossing->crossed_at];
        $records = VisitorRecord::where('vehicle_crossing_id', $crossing->id)->get();
        $failedUpload = data_get($crossing->detection_metadata_json, self::KEY.'.status') === 'failed';
        if (! $crossing->camera_id || (! $crossing->snapshot_path && ! $failedUpload && ! $records->contains(fn ($record) => filled($record->plate_image_path)))) {
            return; // No new association, or insufficient legacy camera context.
        }
        foreach ($records as $record) {
            app(OccurrenceOwnership::class)->record($record, $context);
        }
        $scan = $crossing->rfid_scan_log_id ? RfidScanLog::find($crossing->rfid_scan_log_id) : null;
        if ($scan && ((int) $scan->vehicle_crossing_id !== (int) $crossing->id
            || \App\Models\Gate::normalizeCode($scan->scan_location) !== $crossing->gate)) {
            throw new OccurrenceConflict('Crossing and RFID evidence do not share ownership.');
        }
        $events = VehicleEvent::where(function ($query) use ($records, $scan): void {
            $query->whereIn('visitor_record_id', $records->pluck('id'));
            if ($scan) {
                $query->orWhere('rfid_scan_log_id', $scan->id);
            }
        })->get();
        if ($events->count() > 1) {
            throw new OccurrenceConflict('Crossing image has multiple canonical event owners.');
        }
        foreach ($events as $event) {
            if ((filled($event->external_event_key) && $event->external_event_key !== $crossing->external_event_key)
                || (int) $event->camera_id !== (int) $crossing->camera_id
                || ! $event->event_time?->equalTo($crossing->crossed_at)
                || ($event->rfid_scan_log_id && (! $scan || (int) $scan->id !== (int) $event->rfid_scan_log_id))) {
                throw new OccurrenceConflict('Event image ownership is incompatible with the crossing.');
            }
            if ($event->rfid_scan_log_id && $scan->correlated_vehicle_event_id
                && (int) $scan->correlated_vehicle_event_id !== (int) $event->id) {
                throw new OccurrenceConflict('RFID correlation points to another occurrence.');
            }
        }
        // Validate the entire relationship set before changing any references.
        foreach ($records as $record) {
            if (! $record->snapshot_path || $record->snapshot_path === $previousPath || ! OccurrenceMedia::exists($record->snapshot_path)) {
                $record->forceFill(['snapshot_path' => $crossing->snapshot_path])->save();
            }
        }
        foreach ($events as $event) {
            $updates = [];
            if (! $event->vehicle_image_path || $event->vehicle_image_path === $previousPath || ! OccurrenceMedia::exists($event->vehicle_image_path)) {
                $updates['vehicle_image_path'] = $crossing->snapshot_path;
                $updates['detection_metadata_json'] = array_replace((array) $event->detection_metadata_json,
                    [self::KEY => data_get($crossing->detection_metadata_json, self::KEY)]);
            }
            $record = $records->firstWhere('id', $event->visitor_record_id);
            if (! $event->plate_image_path && $record?->plate_image_path) {
                $updates['plate_image_path'] = $record->plate_image_path;
            }
            if ($updates) {
                $event->forceFill($updates)->save();
            }
        }
    }
}
