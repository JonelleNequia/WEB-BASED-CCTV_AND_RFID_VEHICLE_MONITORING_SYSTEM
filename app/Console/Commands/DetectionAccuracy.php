<?php

namespace App\Console\Commands;

use App\Models\Gate;
use App\Services\DetectionAccuracyService;
use App\Support\VehicleType;
use Illuminate\Console\Command;

/**
 * A2 (detection): vehicle type accuracy from the live gates.
 *
 *   php artisan detection:accuracy               last 7 days, all gates
 *   php artisan detection:accuracy --days=30 --gate=gate-1
 *   php artisan detection:accuracy --from=2026-10-01 --to=2026-10-07   a period (before / after a change)
 *   ... --mistakes                               also list the mistakes with their snapshots
 */
class DetectionAccuracy extends Command
{
    protected $signature = 'detection:accuracy
        {--days=7 : How many days back}
        {--from= : Start date (instead of --days), e.g. 2026-10-01}
        {--to= : End date (default: now)}
        {--gate= : One gate code, e.g. gate-1}
        {--mistakes : List the mistakes with their snapshots}';

    protected $description = 'How well the cameras count vehicles and recognise their type, from the live gates';

    public function handle(DetectionAccuracyService $service): int
    {
        $gate = $this->option('gate') ? Gate::resolveCode((string) $this->option('gate')) : null;
        if ($this->option('gate') && ! $gate) {
            $this->error('Unknown gate: '.$this->option('gate'));

            return self::INVALID;
        }

        try {
            $until = $this->option('to') ? \Carbon\Carbon::parse((string) $this->option('to'))->endOfDay() : now();
            $since = $this->option('from') ? \Carbon\Carbon::parse((string) $this->option('from'))->startOfDay() : now()->subDays(max(1, (int) $this->option('days')));
        } catch (\Throwable) {
            $this->error('Use dates like 2026-10-01.');

            return self::INVALID;
        }

        $report = $service->report($since, $gate, $until);
        $registered = $report['registered'];

        $this->info('Detection accuracy, '.$since->format('M j, Y H:i').' to '.$until->format('M j, Y H:i').', '.($gate ? Gate::labelFor($gate) : 'all gates'));
        $this->newLine();

        // A4: counting per gate.
        $this->line('<options=bold>Counting</> (one vehicle = one event)');
        $this->table(
            ['Gate', 'Crossings', 'Direction unknown', 'Counted twice', 'Dismissed', 'False events', 'Tag reads seen', 'Missed', 'Missed rate'],
            collect($report['counting'])->map(fn (array $row): array => [
                $row['label'], $row['crossings'], $row['direction_unknown'], $row['duplicates'], $row['dismissed'],
                $row['false_rate'] === null ? '—' : $row['false_rate'].'%',
                $row['tag_reads_seen'], $row['missed'], $row['missed_rate'] === null ? '—' : $row['missed_rate'].'%',
            ])->values()->all()
        );
        $this->line('  Missed = a registered tag read while the camera was online, but no vehicle crossing seen.');
        $this->newLine();
        $this->line('<options=bold>Registered vehicles</> (camera type vs Registry type)');
        if ($registered['checked'] === 0) {
            $this->line('  No registered vehicle crossing with a type yet.');
        } else {
            $this->line(sprintf('  %d of %d correct (%.1f%%)', $registered['correct'], $registered['checked'], $registered['accuracy']));
            $this->table(
                ['Registry \\ camera', ...VehicleType::TYPES],
                collect($registered['matrix'])->map(fn (array $row, string $type): array => [$type, ...array_values($row)])->values()->all()
            );
        }
        if ($registered['unchecked'] > 0) {
            $this->line('  '.$registered['unchecked'].' crossing(s) not checked (no type, or the Registry type is "Others").');
        }

        $this->newLine();
        $this->line('<options=bold>Unregistered visitors</> (types corrected by guards)');
        $visitors = $report['visitors'];
        $this->line($visitors['records']
            ? sprintf('  %d of %d camera record(s) corrected (%.1f%%)', $visitors['corrected'], $visitors['records'], $visitors['wrong_rate'])
            : '  No camera records yet.');

        $this->newLine();
        $this->line(sprintf('<options=bold>Car vs Truck/Bus</>: %d car(s) saved as Truck/Bus, %d Truck/Bus saved as Car.', $report['car_as_truck'], $report['truck_as_car']));
        $this->line('Tune it in Settings › Cameras › Vehicle type and Counting.');

        if ($this->option('mistakes')) {
            $this->newLine();
            $mistakes = $service->mistakes($since, $gate, $until);
            $this->line('<options=bold>Mistakes</> (newest first, at most 20)');
            $mistakes === []
                ? $this->line('  None.')
                : $this->table(['Time', 'Gate', 'What', 'Detail', 'Snapshot'], array_map(fn (array $item): array => [
                    $item['time'], $item['gate'], $item['kind'], $item['detail'], $item['snapshot'] ?? '—',
                ], $mistakes));
        }

        return self::SUCCESS;
    }
}
