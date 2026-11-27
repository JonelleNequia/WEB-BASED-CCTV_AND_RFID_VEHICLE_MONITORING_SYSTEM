<?php

namespace Tests\Feature;

use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccountSetupConcurrencyTest extends TestCase
{
    public function test_two_php_processes_cannot_create_two_first_administrators(): void
    {
        $this->race('setup');
    }

    public function test_two_php_processes_cannot_consume_one_activation_twice(): void
    {
        $this->race('activation');
    }

    private function race(string $mode): void
    {
        $database = tempnam(sys_get_temp_dir(), 'philcst-account-race-');
        $workers = [];
        $pdo = null;
        try {
            $fixture = base_path('tests/fixtures/account-setup-worker.php');
            (new Process([PHP_BINARY, $fixture, $database, $mode === 'setup' ? 'initialize' : 'initialize-activation'], base_path(), ['APP_ENV' => 'testing']))->mustRun();
            $pdo = new PDO('sqlite:'.$database);
            $pdo->exec('PRAGMA busy_timeout=5000');
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec('UPDATE account_security_state SET version=version+1 WHERE id=1');
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, $fixture, $database, $mode], base_path(), ['APP_ENV' => 'testing']);
                $worker->setTimeout(15)->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $this->assertTrue(str_contains($worker->getOutput(), 'ready') || $worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
                $this->assertTrue($worker->isRunning());
            }
            $pdo->exec('COMMIT');
            $results = [];
            foreach ($workers as $worker) {
                $results[] = $worker->wait();
                $this->assertSame('', $worker->getErrorOutput());
            }
            sort($results);
            $this->assertSame([0, 2], $results);
            $this->assertSame($mode === 'setup' ? 1 : 2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
            if ($mode === 'activation') {
                $this->assertNull($pdo->query("SELECT activation_hash FROM users WHERE email='guard@school.test'")->fetchColumn());
                $this->assertSame('active', $pdo->query("SELECT account_status FROM users WHERE email='guard@school.test'")->fetchColumn());
                $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM security_events WHERE action='account_activated'")->fetchColumn());
            }
            $this->assertNull($pdo->query('SELECT setup_hash FROM account_security_state')->fetchColumn());
            $this->assertNotEmpty($pdo->query('SELECT setup_completed_at FROM account_security_state')->fetchColumn());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $pdo = null;
            foreach ([$database, $database.'-wal', $database.'-shm', $database.'.test-delivery'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
