<?php

namespace App\Console\Commands;

use App\Models\Gate;
use Illuminate\Console\Command;

/**
 * Windows install kit: the gates and their kiosk path, for
 * deploy/windows/gate-kiosk-shortcut.bat.
 */
class GatesList extends Command
{
    protected $signature = 'gates:list {--json : As JSON}';

    protected $description = 'List the gates and their kiosk page';

    public function handle(): int
    {
        $gates = Gate::ordered()->map(fn (Gate $gate): array => [
            'code' => $gate->code,
            'name' => $gate->name,
            'kiosk_path' => route('gates.kiosk', $gate->code, false),
        ])->values()->all();

        if ($this->option('json')) {
            $this->line((string) json_encode($gates, JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Code', 'Name', 'Kiosk page'], $gates);
        }

        return self::SUCCESS;
    }
}
