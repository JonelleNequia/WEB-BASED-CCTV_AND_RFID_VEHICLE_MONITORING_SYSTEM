<?php

namespace App\Console\Commands;

use App\Models\RfidScanLog;
use App\Services\SystemResetService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * RFID only with a vehicle: remove the old reads that were saved without a
 * vehicle (before reads waited for the camera):
 *
 * - "Unknown tag" reads not linked to a camera crossing;
 * - "Scan only" and still "pending" reads (no crossing, no IN/OUT).
 *
 * Reads with an IN/OUT, or linked to a crossing, are kept. A backup comes
 * first (database copy + the removed rows as JSON in
 * storage/backups/rfid-cleanup-...). --dry-run only lists them.
 */
class CleanupUnmatchedRfidScans extends Command
{
    protected $signature = 'rfid:cleanup-unmatched {--dry-run : Show what would be removed without changing anything}';

    protected $description = 'Remove old unknown-tag and scan-only RFID reads that have no vehicle (backup first)';

    public function handle(SystemResetService $resetService): int
    {
        $query = self::unmatched();
        $total = (clone $query)->count();
        $byKind = (clone $query)->get(['verification_status', 'fusion_status'])
            ->countBy(fn (RfidScanLog $scan): string => $scan->verification_status === 'unknown_tag' || $scan->verification_status === 'guest' ? 'Unknown tag' : 'Scan only / pending');

        $this->table(['Kind', 'Reads'], $byKind->map(fn (int $count, string $kind): array => [$kind, $count])->values()->all());

        if ($total === 0) {
            $this->info('Nothing to remove.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("DRY RUN: would remove {$total} read(s). Nothing was changed.");

            return self::SUCCESS;
        }

        try {
            $folder = $resetService->backupDatabase('rfid-cleanup');
            File::put($folder.'/removed_rfid_scans.json', (clone $query)->get()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $removed = DB::transaction(fn (): int => self::unmatched()->delete());
        } catch (Throwable $exception) {
            $this->error('Stopped, nothing removed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Removed {$removed} read(s). Backup: {$folder}");

        return self::SUCCESS;
    }

    /**
     * Reads saved without a vehicle: no crossing, no IN/OUT.
     *
     * @return Builder<RfidScanLog>
     */
    public static function unmatched(): Builder
    {
        return RfidScanLog::query()
            ->whereNull('vehicle_crossing_id')
            ->whereNull('correlated_vehicle_event_id')
            ->whereNull('resolved_event_type')
            ->whereNotIn('id', DB::table('vehicle_crossings')->whereNotNull('rfid_scan_log_id')->select('rfid_scan_log_id'))
            ->where(fn (Builder $query) => $query
                ->where('verification_status', 'unknown_tag')
                // Unknown tags were stored as "guest" before Phase 3.
                ->orWhere(fn (Builder $legacy) => $legacy->where('verification_status', 'guest')->whereNull('vehicle_id')->whereNull('vehicle_rfid_tag_id'))
                ->orWhereIn('fusion_status', [RfidScanLog::FUSION_SCAN_ONLY, RfidScanLog::FUSION_PENDING]));
    }
}
