<?php

namespace Tests\Feature;

use App\Models\GuestVisit;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\User;
use App\Services\RfidIngestService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 0 (visitor model): the guest pass feature is removed. Old data is
 * migrated, never deleted.
 */
class Phase0GuestPassRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    public function test_migration_turns_passes_into_tags_and_closes_open_visits(): void
    {
        // Data as the guest pass feature left it (like G-01 NFC, G-02/G-03 UHF).
        $nfc = $this->legacyPass('1247495138', 'G-01', 'issued');
        $uhf = $this->legacyPass('E280689400004031D64588E8', 'G-02', 'available');
        $lost = $this->legacyPass('E280689400005031D64568E8', 'G-03', 'lost');
        $visit = DB::table('guest_visits')->insertGetId([
            'rfid_tag_id' => $nfc, 'active_rfid_tag_id' => $nfc, 'plate' => 'YO T047',
            'entry_at' => now()->subDays(4), 'status' => 'overstay', 'notes' => 'Old note',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('system_settings')->insert([
            ['setting_key' => 'guest_pass_validity_minutes', 'setting_value' => '240', 'created_at' => now(), 'updated_at' => now()],
            ['setting_key' => 'guest_pass_require_id', 'setting_value' => '1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        (require database_path('migrations/2026_10_01_000001_remove_guest_passes.php'))->up();

        $this->assertSame(['vehicle', 'disabled'], $this->typeAndStatus($nfc));      // NFC: gate reader cannot read it
        $this->assertSame(['vehicle', 'available'], $this->typeAndStatus($uhf));     // UHF: ordinary spare tag
        $this->assertSame(['vehicle', 'lost'], $this->typeAndStatus($lost));         // a lost card stays lost
        $this->assertSame('G-01', RfidTag::query()->find($nfc)->display_number);    // old label kept for history

        $closed = GuestVisit::query()->findOrFail($visit);
        $this->assertSame(GuestVisit::STATUS_COMPLETED, $closed->status);
        $this->assertNull($closed->active_rfid_tag_id);
        $this->assertStringContainsString('Old note', $closed->notes);
        $this->assertStringContainsString('guest pass removed', $closed->notes);
        $this->assertSame(1, GuestVisit::query()->count(), 'visits are kept, not deleted');
        $this->assertFalse(DB::table('system_settings')->where('setting_key', 'like', 'guest_pass_%')->exists());
    }

    public function test_a_former_pass_read_at_the_gate_never_opens_an_issue_form(): void
    {
        RfidTag::query()->create(['uid' => 'E280689400004031D64588E8', 'tag_number' => 8, 'display_number' => 'G-02', 'status' => RfidTag::STATUS_AVAILABLE]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('stations.rfid-scan', 'gate-1'), ['tag_uid' => 'E280689400004031D64588E8'])
            ->assertCreated()
            ->assertJsonMissingPath('issue')
            ->assertJsonMissingPath('requires_issue')
            ->assertJsonMissingPath('guest_pass');

        $this->assertNotSame('issue_required', $response->json('outcome'));
        $this->assertSame(0, GuestVisit::query()->count());
    }

    public function test_guest_pass_pages_and_actions_are_gone(): void
    {
        $this->actingAs($this->admin)->post('/guest-passes/1/issue')->assertNotFound();
        $this->actingAs($this->admin)->post('/guest-passes/visits/1/card-returned')->assertNotFound();
        $this->actingAs($this->admin)->get('/guest-passes/visits/1')->assertNotFound();
        // Phase 8 (visitor model): the Guests page is the Visitors page.
        $this->actingAs($this->admin)->get('/guests')->assertRedirect(route('visitors.index'));

        $this->actingAs($this->admin)->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee('<span class="nav-label">Guests</span>', false)
            ->assertDontSee('Active Guests');
        $this->actingAs($this->admin)->get(route('settings.index'))->assertOk()->assertDontSee('Guest Pass Rules');
        $this->actingAs($this->admin)->get(route('registry.index', ['tab' => 'tags']))->assertOk()->assertDontSee('Guest Passes');

        // Kiosks: no Issue Guest Pass or Card returned pop-ups.
        $this->actingAs($this->admin)->get(route('gates.kiosk', 'gate-1'))->assertOk()->assertDontSee('data-issue-modal', false);
        $this->actingAs($this->admin)->get(route('gates.kiosk', 'gate-2'))->assertOk()->assertDontSee('data-card-return-modal', false);
    }

    public function test_old_guest_pass_reads_do_not_count_as_a_pass_for_the_camera(): void
    {
        RfidScanLog::query()->create([
            'tag_uid' => 'OLD-PASS-READ',
            'scan_location' => 'gate-1',
            'scan_direction' => 'entry',
            'scan_time' => now(),
            'verification_status' => 'guest_pass_entry',
            'source_mode' => 'hardware_placeholder',
            'reader_name' => 'Entrance UHF Reader',
        ]);

        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])
            ->getJson(route('api.latest-scan', ['camera_role' => 'gate-1', 'event_time' => now()->toIso8601String()]))
            ->assertOk()
            ->assertJsonPath('matched', false)
            ->assertJsonPath('status', 'no_pass')
            ->assertJsonMissingPath('guest_pass');
    }

    public function test_registered_vehicles_still_record_entry_and_exit(): void
    {
        $vehicle = \App\Models\Vehicle::query()->create([
            'plate_number' => 'REG 0001', 'vehicle_owner_name' => 'Faculty', 'category' => 'faculty_staff', 'vehicle_type' => 'Car',
        ]);
        $tag = RfidTag::query()->create(['uid' => 'REG-TAG-1', 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        $this->assertSame('recorded', app(RfidIngestService::class)->ingest(['tag_uid' => 'REG-TAG-1', 'scan_location' => 'gate-1'])->outcome);
        $this->assertSame('INSIDE', $vehicle->fresh()->current_state);
        $this->assertSame('recorded', app(RfidIngestService::class)->ingest(['tag_uid' => 'REG-TAG-1', 'scan_location' => 'gate-2'])->outcome);
        $this->assertSame('OUTSIDE', $vehicle->fresh()->current_state);
    }

    protected function legacyPass(string $uid, string $label, string $status): int
    {
        return DB::table('vehicle_rfid_tags')->insertGetId([
            'uid' => $uid, 'tag_uid' => $uid, 'tag_type' => 'guest_pass', 'display_number' => $label,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function typeAndStatus(int $id): array
    {
        $row = DB::table('vehicle_rfid_tags')->find($id);

        return [$row->tag_type, $row->status];
    }
}
