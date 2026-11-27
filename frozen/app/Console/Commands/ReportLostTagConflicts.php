<?php

namespace App\Console\Commands;

use App\Models\RfidTag;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReportLostTagConflicts extends Command
{
    protected $signature = 'rfid:lost-conflicts';

    protected $description = 'Read-only report of permanent lost UIDs with conflicting live assignments';

    public function handle(): int
    {
        $uids = DB::table('unavailable_rfid_uids')->pluck('uid');
        $tags = RfidTag::query()->get();
        $vehicles = Vehicle::query()->get();
        $rows = [];
        foreach ($uids as $uid) {
            foreach ($tags as $tag) {
                if (RfidTag::normalizeUid((string) $tag->uid) === $uid && $tag->status !== RfidTag::STATUS_LOST) {
                    $rows[] = [$uid, 'Tag #'.$tag->id.' still has status '.$tag->status];
                }
            }
            foreach ($vehicles as $vehicle) {
                if (RfidTag::normalizeUid((string) $vehicle->rfid_tag_uid) !== $uid) {
                    continue;
                }
                $tag = $tags->firstWhere('id', $vehicle->rfid_tag_id);
                if (! $tag || RfidTag::normalizeUid((string) $tag->uid) !== $uid || $tag->status !== RfidTag::STATUS_LOST
                    || ($tag->vehicle_id && (int) $tag->vehicle_id !== (int) $vehicle->id)) {
                    $rows[] = [$uid, 'Vehicle #'.$vehicle->id.' has a conflicting current assignment'];
                }
            }
        }
        $this->table(['Unavailable UID', 'Conflict'], $rows);
        $this->line(count($rows).' conflict(s). Read-only; no assignments or historical references changed.');

        return self::SUCCESS;
    }
}
