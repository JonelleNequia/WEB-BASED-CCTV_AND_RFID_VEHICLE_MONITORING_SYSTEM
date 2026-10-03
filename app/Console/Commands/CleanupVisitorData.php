<?php

namespace App\Console\Commands;

use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\VisitorRecord;
use App\Services\VisitorRecordService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 8 (visitor model): clean up the older guest records.
 *
 * 1. Guest records made by an unknown tag read (not a camera detection; one
 *    tag used to make a new record on every read, e.g. #41-56 from
 *    E280689400004031D6456CE8) are archived: kept, never shown or counted.
 * 2. Real camera guest records become Unregistered Visitor records (plate
 *    profiles, duplicates, dismissed alerts) and are linked to them.
 *
 * Nothing is deleted. --dry-run does all of it inside a transaction, prints
 * the result and rolls it back. Running it again changes nothing new.
 */
class CleanupVisitorData extends Command
{
    protected $signature = 'visitors:cleanup {--dry-run : Show what would change without saving anything}';

    protected $description = 'Archive repeated unknown-tag guest records and convert camera guest records into Unregistered Visitor records';

    public function handle(VisitorRecordService $visitorRecordService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'DRY RUN: nothing will be saved.' : 'Cleaning up guest records.');

        DB::beginTransaction();

        try {
            $archived = $this->archiveUnknownTagRecords($dryRun);
            $converted = $this->convertCameraRecords($visitorRecordService);
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('Stopped, nothing saved: '.$exception->getMessage());

            return self::FAILURE;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->newLine();
        $this->line(sprintf(
            '%s %d unknown-tag guest record(s); %s %d camera guest record(s) into Unregistered Visitor records.',
            $dryRun ? 'Would archive' : 'Archived',
            $archived,
            $dryRun ? 'would convert' : 'converted',
            $converted
        ));

        return self::SUCCESS;
    }

    /**
     * Guest records created by an unknown tag read (linked from the scan log).
     */
    protected function archiveUnknownTagRecords(bool $dryRun): int
    {
        $scans = RfidScanLog::query()
            ->whereNotNull('guest_vehicle_observation_id')
            ->get(['tag_uid', 'guest_vehicle_observation_id']);
        $observations = GuestVehicleObservation::query()
            ->withArchived()
            ->whereNull('archived_at')
            ->whereNull('visitor_record_id')
            ->where(fn ($query) => $query->whereIn('id', $scans->pluck('guest_vehicle_observation_id'))
                ->orWhere('notes', 'like', 'Guest RFID tag %'))
            ->orderBy('id')
            ->get();

        if ($observations->isEmpty()) {
            $this->line('No unknown-tag guest records to archive.');

            return 0;
        }

        $tagFor = $scans->pluck('tag_uid', 'guest_vehicle_observation_id');
        $groups = $observations->groupBy(fn (GuestVehicleObservation $observation): string => (string) ($tagFor[$observation->id]
            ?? (preg_match('/^Guest RFID tag (\S+)/', (string) $observation->notes, $match) ? $match[1] : 'unknown')));

        $this->newLine();
        $this->line('Guest records made by an unknown tag read (not a camera detection):');
        $this->table(
            ['Tag', 'Records', 'IDs', 'Repeated'],
            $groups->map(fn (Collection $group, string $tag): array => [
                $tag,
                $group->count(),
                $this->idList($group->pluck('id')),
                $group->count() > 1 ? 'yes' : 'no',
            ])->values()->all()
        );

        foreach ($groups as $tag => $group) {
            $reason = $group->count() > 1
                ? "Unknown tag {$tag} read {$group->count()} times (one guest record per read before Phase 3); not a camera detection."
                : "Unknown tag {$tag} read; not a camera detection.";

            GuestVehicleObservation::query()->withArchived()->whereIn('id', $group->pluck('id'))
                ->update(['archived_at' => now(), 'archive_reason' => mb_substr($reason, 0, 200)]);
        }

        return $observations->count();
    }

    /**
     * Real camera guest records -> Unregistered Visitor records.
     */
    protected function convertCameraRecords(VisitorRecordService $visitorRecordService): int
    {
        $observations = GuestVehicleObservation::query()
            ->notConverted()
            ->whereIn('observation_source', ['cctv', 'manual'])
            ->orderBy('observed_at')
            ->orderBy('id')
            ->get();

        if ($observations->isEmpty()) {
            $this->line('No camera guest records to convert.');

            return 0;
        }

        $records = $observations->map(fn (GuestVehicleObservation $observation): VisitorRecord => $visitorRecordService->importLegacyObservation($observation));
        $fresh = VisitorRecord::query()->whereIn('id', $records->pluck('id'))->get();

        $this->newLine();
        $this->line('Camera guest records converted into Unregistered Visitor records:');
        $this->table(['', 'Count'], [
            ['Records', $fresh->count()],
            ['Plate read', $fresh->whereNotNull('plate_number')->count()],
            ['Plate unreadable', $fresh->whereNull('plate_number')->count()],
            ['Direction IN / OUT / unknown', $fresh->where('direction', 'IN')->count().' / '.$fresh->where('direction', 'OUT')->count().' / '.$fresh->where('direction', 'UNKNOWN')->count()],
            ['Counted (active)', $fresh->where('status', VisitorRecord::STATUS_ACTIVE)->count()],
            ['Duplicate (same plate within a minute)', $fresh->where('status', VisitorRecord::STATUS_DUPLICATE)->count()],
            ['Dismissed (alert closed by a registered tag)', $fresh->where('status', VisitorRecord::STATUS_DISMISSED)->count()],
            ['Plate profiles', $fresh->pluck('plate_profile_id')->filter()->unique()->count()],
        ]);

        return $observations->count();
    }

    /**
     * "41-44, 47-56"
     *
     * @param  Collection<int, int>  $ids
     */
    protected function idList(Collection $ids): string
    {
        $ranges = [];
        foreach ($ids->sort()->values() as $id) {
            $last = array_key_last($ranges);
            if ($last !== null && $ranges[$last][1] === $id - 1) {
                $ranges[$last][1] = $id;
            } else {
                $ranges[] = [$id, $id];
            }
        }

        return collect($ranges)->map(fn (array $range): string => $range[0] === $range[1] ? '#'.$range[0] : '#'.$range[0].'-'.$range[1])->implode(', ');
    }
}
