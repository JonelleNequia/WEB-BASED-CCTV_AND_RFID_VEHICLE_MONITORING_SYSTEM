<?php

namespace Tests\Feature;

use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GateRemovalConcurrencyTest extends TestCase
{
    public function test_competing_processes_cannot_archive_below_two_gates(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('SQLite is required for the separate-process removal test.');
        }
        $database = tempnam(sys_get_temp_dir(), 'philcst-gate-race-');
        $workers = [];
        $pdo = null;
        try {
            $fixture = base_path('tests/fixtures/gate-removal-worker.php');
            $init = new Process([PHP_BINARY, $fixture, $database, 'initialize'], base_path(), ['APP_ENV' => 'testing']);
            $init->mustRun();
            $pdo = new PDO('sqlite:'.$database);
            $pdo->exec('PRAGMA busy_timeout=5000');
            // Hold the same write lock so BOTH workers reach archive before
            // either can count. They then contend on independent connections.
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec('UPDATE gate_management_locks SET version=version+1 WHERE id=1');
            foreach (['gate-1', 'gate-3'] as $code) {
                $process = new Process([PHP_BINARY, $fixture, $database, $code], base_path(), ['APP_ENV' => 'testing']);
                $process->setTimeout(15);
                $process->start();
                $workers[] = $process;
            }
            foreach ($workers as $worker) {
                $this->assertTrue(str_contains($worker->getOutput(), 'ready') || $worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')), $worker->getErrorOutput());
                $this->assertTrue($worker->isRunning());
            }
            $pdo->exec('COMMIT');
            $exitCodes = [];
            foreach ($workers as $worker) {
                $exitCodes[] = $worker->wait();
                $this->assertSame('', $worker->getErrorOutput());
            }
            sort($exitCodes);
            $this->assertSame([0, 2], $exitCodes, implode("\n", array_map(fn ($worker) => $worker->getOutput(), $workers)));
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM gates WHERE archived_at IS NULL')->fetchColumn());
            $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM gates')->fetchColumn());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $pdo = null;
            foreach ([$database, $database.'-wal', $database.'-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
