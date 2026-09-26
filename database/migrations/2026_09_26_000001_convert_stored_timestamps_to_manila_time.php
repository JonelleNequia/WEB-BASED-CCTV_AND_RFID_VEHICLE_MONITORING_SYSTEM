<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1: move existing timestamps from UTC to Philippine time (UTC+8).
 *
 * Before this, config/app.php used UTC, so values written with now() were
 * UTC. The Python detector and the manual forms, however, already wrote
 * Philippine wall-clock time. This migration shifts only the UTC values.
 *
 * Rollback note: down() restores the columns that were always UTC. For exact
 * restoration use the backup in storage/backups/.
 */
return new class extends Migration
{
    protected const HOURS = 8;

    /**
     * Columns that were always written in UTC.
     *
     * @var array<string, list<string>>
     */
    protected array $utcColumns = [
        'users' => ['email_verified_at', 'created_at', 'updated_at'],
        'password_reset_tokens' => ['created_at'],
        'failed_jobs' => ['failed_at'],
        'cameras' => ['created_at', 'updated_at', 'last_connected_at'],
        'rois' => ['created_at', 'updated_at'],
        'system_settings' => ['created_at', 'updated_at'],
        'event_receive_logs' => ['created_at', 'updated_at'],
        'rfid_scan_logs' => ['scan_time', 'created_at', 'updated_at'],
        'vehicles' => ['created_at', 'updated_at', 'first_entry_today_at', 'last_exit_today_at', 'last_entry_at', 'last_exit_at', 'last_seen_at'],
        'vehicle_rfid_tags' => ['assigned_at', 'last_scanned_at', 'created_at', 'updated_at'],
        'vehicle_events' => ['created_at', 'updated_at', 'details_completed_at'],
        'guest_vehicle_observations' => ['created_at', 'updated_at'],
        'active_sessions' => ['created_at', 'updated_at'],
    ];

    /**
     * Columns that hold a mix of UTC and Philippine time. A value that is at
     * least 7 hours ahead of the same row's (UTC) created_at is already in
     * Philippine time and is left alone.
     *
     * @var array<string, array{columns: list<string>, keep_when: array<string, list<string>>}>
     */
    protected array $mixedColumns = [
        'vehicle_events' => [
            'columns' => ['event_time'],
            'keep_when' => ['event_origin' => ['manual', 'guest_manual']],
        ],
        'guest_vehicle_observations' => [
            'columns' => ['observed_at'],
            'keep_when' => ['observation_source' => ['manual']],
        ],
        'active_sessions' => [
            'columns' => ['entry_time', 'time_out'],
            'keep_when' => [],
        ],
    ];

    public function up(): void
    {
        // Mixed columns first: they are compared with created_at while
        // created_at is still in UTC.
        foreach ($this->mixedColumns as $table => $rules) {
            foreach ($rules['columns'] as $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $query = DB::table($table)
                    ->whereNotNull($column)
                    ->where(function ($query) use ($column): void {
                        $query->whereNull('created_at')
                            ->orWhereRaw($this->hoursBetween($column, 'created_at').' < 7');
                    });

                foreach ($rules['keep_when'] as $keepColumn => $values) {
                    if (Schema::hasColumn($table, $keepColumn)) {
                        $query->where(function ($query) use ($keepColumn, $values): void {
                            $query->whereNull($keepColumn)->orWhereNotIn($keepColumn, $values);
                        });
                    }
                }

                $query->update([$column => DB::raw($this->addHours($column, self::HOURS))]);
            }
        }

        $this->shiftUtcColumns(self::HOURS);
    }

    public function down(): void
    {
        $this->shiftUtcColumns(-self::HOURS);
    }

    protected function shiftUtcColumns(int $hours): void
    {
        foreach ($this->utcColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)
                    ->whereNotNull($column)
                    ->update([$column => DB::raw($this->addHours($column, $hours))]);
            }
        }
    }

    protected function addHours(string $column, int $hours): string
    {
        $wrapped = DB::getQueryGrammar()->wrap($column);

        return DB::getDriverName() === 'sqlite'
            ? sprintf("datetime(%s, '%+d hours')", $wrapped, $hours)
            : sprintf('DATE_ADD(%s, INTERVAL %d HOUR)', $wrapped, $hours);
    }

    protected function hoursBetween(string $later, string $earlier): string
    {
        $grammar = DB::getQueryGrammar();

        return DB::getDriverName() === 'sqlite'
            ? sprintf('((julianday(%s) - julianday(%s)) * 24)', $grammar->wrap($later), $grammar->wrap($earlier))
            : sprintf('(TIMESTAMPDIFF(MINUTE, %s, %s) / 60)', $grammar->wrap($earlier), $grammar->wrap($later));
    }
};
