<?php

namespace Tests\Feature;

use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlateCorrectionConcurrencyTest extends TestCase
{
    public function test_independent_competing_corrections_write_one_audit_and_one_movement(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'philcst-plate-race-');
        $workers = [];
        $pdo = null;
        try {
            $fixture = base_path('tests/fixtures/plate-correction-worker.php');
            (new Process([PHP_BINARY, $fixture, $database, 'initialize'], base_path(), ['APP_ENV' => 'testing']))->mustRun();
            $pdo = new PDO('sqlite:'.$database);
            $pdo->exec('PRAGMA busy_timeout=5000');
            // Both connections must contend on the first write before reading
            // classification/profile/audit state; lockForUpdate alone is a no-op on SQLite.
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec('UPDATE plate_processing_locks SET version=version+1 WHERE id=1');
            foreach (range(1, 2) as $index) {
                $worker = new Process([PHP_BINARY, $fixture, $database, 'correct'], base_path(), ['APP_ENV' => 'testing']);
                $worker->setTimeout(15);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $this->assertTrue(str_contains($worker->getOutput(), 'ready') || $worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
                $this->assertTrue($worker->isRunning());
            }
            $pdo->exec('COMMIT');
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            }
            foreach (['visitor_records' => 1, 'vehicle_crossings' => 1, 'vehicle_events' => 1, 'review_actions' => 1] as $table => $expected) {
                $this->assertSame($expected, (int) $pdo->query("SELECT count(*) FROM $table")->fetchColumn());
            }
            $this->assertSame('registered', $pdo->query('SELECT registration_status FROM visitor_records')->fetchColumn());
            $this->assertSame('RCE 0001', $pdo->query('SELECT plate_number FROM visitor_records')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT times FROM plate_corrections')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT visit_count FROM plate_profiles')->fetchColumn());
            $this->assertSame('INSIDE', $pdo->query('SELECT current_state FROM vehicles')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT entries_today_count FROM vehicles')->fetchColumn());
            $this->assertSame('Race Fixture Registrar', $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='registration_staff_name'")->fetchColumn());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $pdo = null;
            foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
