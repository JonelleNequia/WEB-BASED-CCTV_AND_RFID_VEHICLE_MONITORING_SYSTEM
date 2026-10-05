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
 */
class DetectionAccuracy extends Command
{
    protected $signature = 'detection:accuracy {--days=7 : How many days back} {--gate= : One gate code, e.g. gate-1}';

    protected $description = 'How often the camera gets the vehicle type right (Registry types and guard corrections)';

    public function handle(DetectionAccuracyService $service): int
    {
        $gate = $this->option('gate') ? Gate::resolveCode((string) $this->option('gate')) : null;
        if ($this->option('gate') && ! $gate) {
            $this->error('Unknown gate: '.$this->option('gate'));

            return self::INVALID;
        }

        $days = max(1, (int) $this->option('days'));
        $report = $service->report(now()->subDays($days), $gate);
        $registered = $report['registered'];

        $this->info('Vehicle type accuracy, last '.$days.' day(s), '.($gate ? Gate::labelFor($gate) : 'all gates'));
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
        $this->line('Tune it in Settings › Cameras › Vehicle type.');

        return self::SUCCESS;
    }
}
