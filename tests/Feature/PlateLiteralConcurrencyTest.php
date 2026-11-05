<?php

namespace Tests\Feature;

use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlateLiteralConcurrencyTest extends TestCase
{
    public function test_orphan_event_is_shared_by_competing_manual_ocr_and_rfid_evidence(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'philcst-literal-race-');
        $workers = [];
        $pdo = null;
        try {
            $fixture = base_path('tests/fixtures/plate-literal-worker.php');
            (new Process([PHP_BINARY, $fixture, $database, 'initialize'], base_path(), ['APP_ENV' => 'testing']))->mustRun();
            $pdo = new PDO('sqlite:'.$database);
            $pdo->exec('PRAGMA busy_timeout=5000');
            // Both connections must contend on the first write before reading
            // classification/profile/audit state; lockForUpdate alone is a no-op on SQLite.
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec('UPDATE plate_processing_locks SET version=version+1 WHERE id=1');
            foreach (['correct', 'correct', 'ocr', 'rfid'] as $command) {
                $worker = new Process([PHP_BINARY, $fixture, $database, $command], base_path(), ['APP_ENV' => 'testing']);
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
            $this->assertSame('395ISX', $pdo->query('SELECT plate_number FROM visitor_records')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT id FROM vehicle_events')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT visitor_record_id FROM vehicle_events')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT rfid_scan_log_id FROM vehicle_events')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM rfid_scan_logs')->fetchColumn());
            $this->assertSame('retained/race.jpg', $pdo->query('SELECT vehicle_image_path FROM vehicle_events')->fetchColumn());
            $this->assertLessThanOrEqual(1, (int) $pdo->query('SELECT sum(visit_count) FROM plate_profiles')->fetchColumn());
            $this->assertSame('INSIDE', $pdo->query('SELECT current_state FROM vehicles')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT entries_today_count FROM vehicles')->fetchColumn());
            $this->assertSame('OUTSIDE', $pdo->query('SELECT current_state FROM vehicles WHERE id=2')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT entries_today_count FROM vehicles WHERE id=2')->fetchColumn());
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
