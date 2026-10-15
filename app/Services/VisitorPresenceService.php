<?php

namespace App\Services;

use App\Models\PlateProfile;
use App\Models\VisitorRecord;
use App\Support\PhilippineTime;
use Illuminate\Support\Collection;

/**
 * Gate direction work, Phase C: one plate's passes as trips (IN at one gate
 * -> OUT at the same or another gate, and the time inside), and the
 * unregistered vehicles that came IN today and have not gone OUT yet
 * ("possibly still inside": an estimate only; the official inside count is
 * for registered vehicles).
 */
class VisitorPresenceService
{
    /**
     * Newest first: [{in: ?VisitorRecord, out: ?VisitorRecord, minutes: ?int}, ...].
     * An IN and the next OUT of the same day are one trip.
     *
     * @return Collection<int, array{in: ?VisitorRecord, out: ?VisitorRecord, minutes: ?int}>
     */
    public function trips(PlateProfile $profile, int $limit = 100): Collection
    {
        $records = VisitorRecord::query()->active()->where('plate_profile_id', $profile->id)
            ->whereIn('direction', ['IN', 'OUT'])->orderBy('seen_at')->limit($limit)->get();
        $trips = [];
        $open = null;

        foreach ($records as $record) {
            if ($record->direction === 'IN') {
                if ($open) {
                    $trips[] = ['in' => $open, 'out' => null, 'minutes' => null];
                }
                $open = $record;

                continue;
            }

            if ($open && PhilippineTime::localDateString($open->seen_at) === PhilippineTime::localDateString($record->seen_at)) {
                $trips[] = ['in' => $open, 'out' => $record, 'minutes' => (int) round($open->seen_at->diffInMinutes($record->seen_at, true))];
            } else {
                // An IN of another day stays alone; this OUT has no IN.
                if ($open) {
                    $trips[] = ['in' => $open, 'out' => null, 'minutes' => null];
                }
                $trips[] = ['in' => null, 'out' => $record, 'minutes' => null];
            }
            $open = null;
        }
        if ($open) {
            $trips[] = ['in' => $open, 'out' => null, 'minutes' => null];
        }

        return collect($trips)->reverse()->values();
    }

    /**
     * Unregistered vehicles whose last pass today is IN (an estimate: a
     * missed or unread OUT keeps a vehicle here), and today's IN passes with
     * no plate (they cannot be paired).
     *
     * @return array{vehicles: Collection<int, array{profile: PlateProfile, record: VisitorRecord}>, without_plate: int}
     */
    public function possiblyInside(): array
    {
        $today = VisitorRecord::query()->active()->whereIn('direction', ['IN', 'OUT'])
            ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['seen_at']))
            ->with('plateProfile')
            ->orderBy('seen_at')
            ->get();

        $vehicles = $today->whereNotNull('plate_profile_id')
            ->groupBy('plate_profile_id')
            ->map(fn (Collection $records) => $records->last())
            ->filter(fn (VisitorRecord $last) => $last->direction === 'IN' && $last->plateProfile && $last->plateProfile->vehicle_id === null)
            ->sortByDesc(fn (VisitorRecord $last) => $last->seen_at->getTimestamp())
            ->map(fn (VisitorRecord $last) => ['profile' => $last->plateProfile, 'record' => $last])
            ->values();

        return [
            'vehicles' => $vehicles,
            'without_plate' => $today->whereNull('plate_profile_id')->where('direction', 'IN')->count(),
        ];
    }
}
