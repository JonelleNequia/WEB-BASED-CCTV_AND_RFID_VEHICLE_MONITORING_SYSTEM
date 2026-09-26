<?php

namespace App\Console\Commands;

use App\Models\ActiveSession;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2: archive old open guest sessions created by CCTV detection.
 *
 * CCTV guest entries opened a session that was only closed if the detector
 * later matched the same plate (or type + color) at the exit, so most stayed
 * "open" forever and inflated "Vehicles Inside". Guest passes replace that
 * flow, so these sessions are archived (kept for history, no longer inside).
 */
class CloseStaleGuestSessions extends Command
{
    protected $signature = 'guests:close-stale-sessions
        {--dry-run : List the sessions that would be archived without changing anything}
        {--older-than=0 : Only sessions that opened at least this many hours ago}';

    protected $description = 'Archive stale open guest sessions left by CCTV guest detection';

    public function handle(): int
    {
        $hours = max(0, (int) $this->option('older-than'));
        $sessions = $this->staleSessionsQuery($hours)->with('entryEvent')->orderBy('entry_time')->get();

        if ($sessions->isEmpty()) {
            $this->info('No open guest sessions to archive.');

            return self::SUCCESS;
        }

        $this->table(
            ['Session', 'Entry time', 'Plate', 'Source', 'Entry event'],
            $sessions->map(fn (ActiveSession $session): array => [
                $session->id,
                $session->entry_time?->format('Y-m-d h:i A') ?? 'n/a',
                $session->plate_text ?: $session->plate_number ?: 'no plate',
                $session->entryEvent?->event_origin ?? 'n/a',
                $session->entry_event_id,
            ])->all()
        );

        if ($this->option('dry-run')) {
            $this->warn("Dry run: {$sessions->count()} session(s) would be archived. Nothing was changed.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($sessions): void {
            foreach ($sessions as $session) {
                $session->forceFill([
                    'status' => 'archived',
                    'archived_at' => now(),
                    'archive_reason' => 'Stale CCTV guest session archived by guests:close-stale-sessions.',
                ])->save();

                $session->entryEvent?->forceFill(['match_status' => 'archived'])->save();
            }
        });

        $this->info("Archived {$sessions->count()} guest session(s). They no longer count as inside.");

        return self::SUCCESS;
    }

    protected function staleSessionsQuery(int $hours): Builder
    {
        return ActiveSession::query()
            ->where('status', 'open')
            ->when($hours > 0, fn (Builder $query) => $query->where('entry_time', '<=', now()->subHours($hours)))
            ->whereHas('entryEvent', function (Builder $query): void {
                $query->where(function (Builder $guestQuery): void {
                    $guestQuery->where('vehicle_category', 'guest')
                        ->orWhereIn('event_origin', ['guest_cctv', 'guest_manual']);
                });
            });
    }
}
