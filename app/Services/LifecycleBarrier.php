<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/** One local SQLite writer reservation, held through HTTP/CLI application writes. */
class LifecycleBarrier
{
    private ?PDO $connection = null;
    private int $depth = 0;

    public function enabled(): bool { return filled(config('lifecycle.root')); }

    public function metadata(string $name): array
    {
        $data = json_decode(file_get_contents(config('lifecycle.root').'/'.$name), true, flags: JSON_THROW_ON_ERROR);
        if (($data['schema'] ?? null) !== 1) throw new RuntimeException('Invalid lifecycle metadata.');
        return $data;
    }

    public function checkIdentity(): array
    {
        $identity = $this->metadata('identity.json');
        $stored = DB::table('delivery_installations')->where('id', 1)->first();
        if (! $stored || $stored->installation !== ($identity['installation'] ?? null) || $stored->generation !== ($identity['generation'] ?? null)) {
            throw new RuntimeException('Installation/database generation mismatch. Recovery required.');
        }
        return $identity;
    }

    public function acquire(bool $delivery = false, bool $control = false): void
    {
        if (! $this->enabled()) return;
        if ($this->depth++ > 0) return;
        try {
            $file = config('lifecycle.root').'/coordination/barrier.sqlite';
            if (! is_file($file)) throw new RuntimeException('Missing writer barrier.');
            $this->connection = new PDO('sqlite:'.$file, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->connection->exec('PRAGMA busy_timeout=15000');
            $this->connection->exec('BEGIN IMMEDIATE');
            $state = $this->metadata('maintenance.json');
            $phase = $state['phase'] ?? 'failed';
            $controlAllowed = $control && $phase === 'resuming'
                && hash_equals((string) ($state['operation'] ?? ''), (string) getenv('PHILCST_LIFECYCLE_CONTROL'));
            if ($phase !== 'running' && ! ($delivery && $phase === 'quiescing') && ! $controlAllowed) {
                throw new RuntimeException('Maintenance active.');
            }
            $this->checkIdentity();
        } catch (\Throwable $e) {
            $this->release();
            throw $e;
        }
    }

    public function release(): void
    {
        if ($this->depth > 1) { $this->depth--; return; }
        if ($this->connection) {
            try { $this->connection->exec('ROLLBACK'); } catch (\Throwable) {}
        }
        $this->connection = null;
        $this->depth = 0;
    }

    public function run(callable $work): mixed
    {
        $this->acquire();
        try { return $work(); } finally { $this->release(); }
    }
}
