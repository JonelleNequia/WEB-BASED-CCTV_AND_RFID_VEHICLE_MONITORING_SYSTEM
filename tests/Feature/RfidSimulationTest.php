<?php

namespace Tests\Feature;

use App\Models\RfidScanLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RfidSimulationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure the RFID scan page renders the offline simulation interface.
     */
    public function test_rfid_scan_page_renders_simulation_workspace(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->get(route('rfid-scans.index'))
            ->assertOk()
            ->assertSee('RFID Scan Simulation')
            ->assertSee('Simulate RFID Scan');
    }

    /**
     * Ensure simulating an RFID scan creates a local RFID log.
     */
    public function test_simulated_rfid_scan_creates_a_scan_log(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->post(route('rfid-scans.store'), [
                'tag_uid' => 'RFID-ABC-1001',
                'scan_location' => 'entrance',
                'scan_direction' => 'entry',
                'reader_name' => 'Entrance RFID Reader (Simulated)',
                'scan_time' => now()->toIso8601String(),
                'notes' => 'Created from feature test.',
            ])
            ->assertRedirect();

        $scanLog = RfidScanLog::query()->latest('id')->first();

        $this->assertNotNull($scanLog);
        $this->assertSame('RFID-ABC-1001', $scanLog->tag_uid);
        $this->assertSame('entrance', $scanLog->scan_location);
    }
}
