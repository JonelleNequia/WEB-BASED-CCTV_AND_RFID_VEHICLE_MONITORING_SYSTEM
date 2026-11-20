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
        if ($ledger = $this->recoverySecurity()) {
            $marker = DB::table('recovery_state')->where('id', 1)->first();
            if (! $marker || (int) $marker->epoch !== ($ledger['epoch'] ?? null) || $marker->active_set !== ($ledger['active_set'] ?? null)
                || $stored->installation !== ($ledger['installation'] ?? null) || $stored->generation !== ($ledger['generation'] ?? null)) {
                throw new RuntimeException('Recovery/database epoch mismatch. Keep maintenance.');
            }
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
            $this->checkRecoveryAdmission($state);
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

    private function recoverySecurity(): ?array
    {
        $path = config('lifecycle.root').'/recovery-security.json';
        if (! is_file($path)) return null;
        $ledger = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (($ledger['schema'] ?? null) !== 'philcst.recovery-security.v1' || ! is_int($ledger['epoch'] ?? null)
            || ! is_string($ledger['active_set'] ?? null) || ! is_array($ledger['lost_uids'] ?? null)
            || ! is_array($ledger['revoked_update_keys'] ?? null) || ! is_int($ledger['update_generation'] ?? null)
            || $ledger['epoch'] < 0 || $ledger['update_generation'] < 0
            || ! is_array($ledger['blocked_by_set'] ?? null) || ! array_key_exists($ledger['active_set'], $ledger['blocked_by_set'])) throw new RuntimeException('Invalid protected recovery ledger.');

        return $ledger;
    }

    private function checkRecoveryAdmission(array $state): void
    {
        $guard = config('lifecycle.root').'/recovery-guard.json';
        $security = config('lifecycle.root').'/recovery-security.json';
        if (($state['recovery_required'] ?? false) || is_file($guard) || is_file($security)) {
            if (! is_file($guard) || ! is_file($security) || ($this->metadata('recovery-guard.json')['status'] ?? null) !== 'validated') {
                throw new RuntimeException('Recovery is incomplete. Writers remain fenced.');
            }
        }
    }

    /** Previously committed receipts are checked before this quarantine policy. */
    public function assertRecoveryDeliveryAllowed(string $operation): void
    {
        if (! $this->enabled()) return;
        $ledger = $this->recoverySecurity();
        if ($ledger && isset($ledger['blocked_by_set'][$ledger['active_set']][$operation])) {
            abort(409, 'This delivery belongs to activity outside the selected recovery point; retained for separately reviewed recovery.');
        }
    }

    public function run(callable $work): mixed
    {
        $this->acquire();
        try { return $work(); } finally { $this->release(); }
    }
}
