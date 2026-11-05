<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RegistrationSnapshotConcurrencyTest extends TestCase
{
    public function test_competing_image_callbacks_share_one_image_and_one_canonical_movement(): void
    {
        $work = sys_get_temp_dir().'/philcst-snapshot-race-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($work);
        $workers = [];
        $pdo = null;
        try {
            $fixture = base_path('tests/fixtures/snapshot-worker.php');
            (new Process([PHP_BINARY, $fixture, $work, 'initialize'], base_path()))->mustRun();
            file_put_contents($work.'/event-time.txt', now()->addMinute()->startOfSecond()->toIso8601String());
            $pdo = new PDO('sqlite:'.$work.'/db.sqlite');
            $pdo->exec('PRAGMA busy_timeout=5000');
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec('UPDATE plate_processing_locks SET version=version+1 WHERE id=1');
            foreach (range(1, 2) as $number) {
                $worker = new Process([PHP_BINARY, $fixture, $work, 'callback'], base_path());
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $this->assertTrue(str_contains($worker->getOutput(), 'ready') || $worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
            }
            // Both uploads must finish while the identity writer is blocked.
            $deadline = microtime(true) + 4;
            do {
                $staged = glob($work.'/media/*.jpg');
                if (count($staged) === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertCount(2, $staged);
            $pdo->exec('COMMIT');
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
            }
            foreach (['vehicle_crossings', 'visitor_records', 'vehicle_events'] as $table) {
                $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM '.$table)->fetchColumn());
            }
            $image = $pdo->query('SELECT snapshot_path FROM vehicle_crossings')->fetchColumn();
            $this->assertSame($image, $pdo->query('SELECT vehicle_image_path FROM vehicle_events')->fetchColumn());
            $this->assertSame($image, $pdo->query('SELECT snapshot_path FROM visitor_records')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT entries_today_count FROM vehicles')->fetchColumn());
            $this->assertCount(1, glob($work.'/media/*.jpg'));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(.2);
                }
            }
            $pdo = null;
            File::deleteDirectory($work);
        }
    }
}
