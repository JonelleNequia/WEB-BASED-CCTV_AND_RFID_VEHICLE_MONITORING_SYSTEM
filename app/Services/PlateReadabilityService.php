<?php

namespace App\Services;

use App\Models\Gate;
use App\Models\VisitorRecord;
use Illuminate\Support\Collection;

/**
 * Gate direction work, Phase B: how readable the plates are at each gate,
 * from the detector's plate measurements of recent Unregistered Visitor
 * passes (the plate crop's width in pixels and its sharpness, see
 * plate_reader.py), with what to change on the camera. The system never
 * changes camera settings by itself.
 */
class PlateReadabilityService
{
    /** A plate this wide (main stream, pixels) has enough detail; same as plate_reader.GOOD_PLATE_WIDTH. */
    public const GOOD_PLATE_WIDTH = 150;

    /** Sharpness (Laplacian at 64 px high) of a crop that is half sharp; same as plate_reader.HALF_SHARP. */
    public const HALF_SHARP = 600;

    /**
     * Recommended settings for a TP-Link VIGI camera reading plates of
     * moving vehicles (Settings in the camera's web page or the VIGI app).
     *
     * @var list<array{0: string, 1: string}>
     */
    public const RECOMMENDED = [
        ['Shutter', '1/500 s or faster (1/1000 s for fast vehicles): Image › Exposure › Shutter. A slow shutter blurs the plate.'],
        ['WDR', 'On (Image › WDR) at gates with bright sky or headlights behind the vehicle.'],
        ['Night', 'Day/Night: Auto with Smart IR on, so the plate is lit but not washed out white.'],
        ['Main stream', 'Highest resolution, H.264 or H.265, 4096-6144 kbps, 15-25 fps (Video › Main stream). The plate is read from this stream.'],
        ['Focus', 'Focused on the place where plates pass (not on the far road); lock it after.'],
        ['Position', 'The plate about 150 px wide in the main stream; camera 2-3 m high; seeing the plate from behind, less than 30° from the side or from above.'],
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function report(int $passes = 50): array
    {
        return Gate::ordered()->mapWithKeys(function (Gate $gate) use ($passes): array {
            $records = VisitorRecord::query()
                ->whereIn('gate', [$gate->code])
                ->where('source', VisitorRecord::SOURCE_CAMERA)
                ->latest('seen_at')
                ->limit($passes)
                ->get(['plate_status', 'plate_confidence', 'ocr_details_json']);
            $measured = $records->map(fn (VisitorRecord $record) => data_get($record->ocr_details_json, 'best_crop'))->filter(fn ($crop) => is_array($crop));
            $width = $this->median($measured->pluck('width_px'));
            $sharpness = $this->median($measured->pluck('sharpness'));
            $read = $records->whereIn('plate_status', [VisitorRecord::PLATE_READ, VisitorRecord::PLATE_CORRECTED])->count();
            $unreadable = $records->where('plate_status', VisitorRecord::PLATE_UNREADABLE)->count();
            $noPlate = $records->filter(fn (VisitorRecord $record) => data_get($record->ocr_details_json, 'reader') === 'plate_detector'
                && (int) data_get($record->ocr_details_json, 'plates_found', 0) === 0)->count();

            return [$gate->code => [
                'name' => $gate->name,
                'passes' => $records->count(),
                'measured' => $measured->count(),
                'read' => $read,
                'unreadable' => $unreadable,
                'no_plate_found' => $noPlate,
                'median_width_px' => $width,
                'median_sharpness' => $sharpness,
                'hints' => $this->hints($records->count(), $measured->count(), $width, $sharpness, $noPlate),
            ]];
        })->all();
    }

    /**
     * @return list<string>
     */
    protected function hints(int $passes, int $measured, ?float $width, ?float $sharpness, int $noPlate): array
    {
        if ($passes === 0) {
            return ['No unregistered vehicle has passed yet: nothing to measure.'];
        }
        if ($measured === 0) {
            return ['These passes were read before plate measuring (or without the plate detector): let a few vehicles pass, then look again.'];
        }

        $hints = [];
        if ($width !== null && $width < self::GOOD_PLATE_WIDTH * 0.67) {
            $hints[] = 'Plates are small (about '.round($width).' px wide; aim for about '.self::GOOD_PLATE_WIDTH.' px): zoom in, or move the camera closer to where vehicles pass.';
        } elseif ($width !== null && $width < self::GOOD_PLATE_WIDTH) {
            $hints[] = 'Plates are a little small (about '.round($width).' px wide; aim for about '.self::GOOD_PLATE_WIDTH.' px): zoom in a little.';
        }
        if ($sharpness !== null && $sharpness < self::HALF_SHARP) {
            $hints[] = 'Plates are blurred (sharpness '.round($sharpness).'; '.self::HALF_SHARP.' or more is sharp): set a faster shutter (1/500 s or faster) and check the focus.';
        }
        if ($noPlate > $passes / 3) {
            $hints[] = "No plate was found in {$noPlate} of {$passes} passes: check that the camera sees the plates (from behind, not too much from the side), and that the zone covers where the plate is clearest.";
        }

        return $hints === [] ? ['Plates are big and sharp enough.'] : $hints;
    }

    protected function median(Collection $values): ?float
    {
        $sorted = $values->filter(fn ($value) => is_numeric($value))->map(fn ($value) => (float) $value)->sort()->values();
        $count = $sorted->count();
        if ($count === 0) {
            return null;
        }

        return $count % 2 ? $sorted[intdiv($count, 2)] : ($sorted[$count / 2 - 1] + $sorted[$count / 2]) / 2;
    }
}
