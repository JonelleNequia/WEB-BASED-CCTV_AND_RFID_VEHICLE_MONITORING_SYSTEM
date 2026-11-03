<?php

namespace Tests\Feature;

use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RegistrationPhase3ConcurrencyTest extends TestCase
{
    private function compete(string $setup, array $commands, callable $assertions): void
    {
        $database = tempnam(sys_get_temp_dir(), 'philcst-phase3-race-');
        $workers = [];
        $pdo = null;
        try {
            $fixture = base_path('tests/fixtures/registration-phase3-worker.php');
            (new Process([PHP_BINARY, $fixture, $database, 'initialize-'.$setup], base_path(), ['APP_ENV' => 'testing']))->mustRun();
            $pdo = new PDO('sqlite:'.$database);
            $pdo->exec('PRAGMA busy_timeout=5000');
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec('UPDATE plate_processing_locks SET version=version+1 WHERE id=1');
            foreach ($commands as $arguments) {
                $worker = new Process([PHP_BINARY, $fixture, $database, ...$arguments], base_path(), ['APP_ENV' => 'testing']);
                $worker->setTimeout(15);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $this->assertTrue(str_contains($worker->getOutput(), 'ready') || $worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
                $this->assertTrue($worker->isRunning());
            }
            $pdo->exec('COMMIT');
            $codes = [];
            foreach ($workers as $worker) {
                $codes[] = $worker->wait();
                $this->assertSame('', $worker->getErrorOutput());
            }
            sort($codes);
            $assertions($pdo, $codes);
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

    public function test_two_vehicles_cannot_claim_the_same_available_tag(): void
    {
        $this->compete('assign', [['assign', '1', '1'], ['assign', '2', '1']], function (PDO $pdo, array $codes): void {
            $this->assertSame([0, 2], $codes);
            $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM vehicles WHERE rfid_tag_id=1')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query("SELECT count(*) FROM vehicle_rfid_tags WHERE status='assigned'")->fetchColumn());
            $this->assertSame('Concurrency Registrar', $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='registration_staff_name'")->fetchColumn());
        });
    }

    public function test_concurrent_replacements_reject_the_stale_assignment_and_preserve_occupancy(): void
    {
        $this->compete('replace', [['replace', '1', '2'], ['replace', '1', '3']], function (PDO $pdo, array $codes): void {
            $this->assertSame([0, 2], $codes);
            $this->assertSame(1, (int) $pdo->query("SELECT count(*) FROM vehicle_rfid_tags WHERE status='assigned'")->fetchColumn());
            $this->assertSame('lost', $pdo->query('SELECT status FROM vehicle_rfid_tags WHERE id=1')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM unavailable_rfid_uids')->fetchColumn());
            $this->assertSame('INSIDE', $pdo->query('SELECT current_state FROM vehicles WHERE id=1')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT entries_today_count FROM vehicles WHERE id=1')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM vehicle_events')->fetchColumn());
        });
    }

    public function test_competing_plate_confirmation_and_rfid_evidence_share_one_occurrence(): void
    {
        $this->compete('evidence', [['evidence-plate'], ['evidence-rfid']], function (PDO $pdo, array $codes): void {
            $this->assertSame([0, 0], $codes);
            $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM vehicle_events')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM vehicle_crossings')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM rfid_scan_logs')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM review_actions')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT entries_today_count FROM vehicles WHERE id=1')->fetchColumn());
            $this->assertSame('INSIDE', $pdo->query('SELECT current_state FROM vehicles WHERE id=1')->fetchColumn());
        });
    }
}
