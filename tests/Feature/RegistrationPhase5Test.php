<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\Gate;
use App\Models\GuestVehicleObservation;
use App\Models\NetworkDevice;
use App\Models\RfidScanLog;
use App\Models\User;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\GateManagementService;
use App\Services\MovementCountService;
use App\Services\VehicleRegistryService;
use App\Support\VehicleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class RegistrationPhase5Test extends TestCase
{
    use RefreshDatabase;

    private Gate $archived;

    private Gate $inactive;

    private Camera $camera;

    private VehicleEvent $event;

    private VisitorRecord $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        Storage::fake('public');
        $this->travelTo(now()->startOfDay()->addHours(12));
        $this->inactive = app(GateManagementService::class)->create('Inactive side gate');
        $this->inactive->forceFill(['is_active' => false])->save();
        $this->archived = app(GateManagementService::class)->create('Old history gate');
        $this->archived->forceFill(['archived_at' => now(), 'is_active' => false, 'pass_min_move' => 29])->save();
        $this->camera = Camera::create(['camera_name' => 'Historical camera', 'camera_role' => $this->archived->code,
            'source_type' => 'none', 'source_value' => '', 'is_active' => false,
            'calibration_mask_json' => [['x' => 0, 'y' => 0], ['x' => 1, 'y' => 0], ['x' => 1, 'y' => 1]]]);
        $vehicle = app(VehicleRegistryService::class)->register(['plate_number' => 'HST-9001', 'vehicle_type' => 'Car', 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Original Registrar']);
        $this->event = VehicleEvent::create(['vehicle_id' => $vehicle->id, 'camera_id' => $this->camera->id,
            'plate_text' => $vehicle->plate_number, 'vehicle_type' => 'Car', 'vehicle_category' => 'faculty_staff',
            'event_type' => 'ENTRY', 'event_origin' => 'manual', 'event_status' => 'completed', 'event_time' => now(),
            'vehicle_image_path' => 'retained/history.jpg']);
        $this->guest = VisitorRecord::create(['external_event_key' => 'phase5-history', 'gate' => $this->archived->code,
            'camera_id' => $this->camera->id, 'direction' => 'IN', 'seen_at' => now(), 'status' => 'active',
            'plate_status' => 'read', 'plate_number' => 'HST-9002', 'plate_key' => 'HST9002', 'registration_status' => 'guest',
            'status_note' => 'Visitor wording supplied in historical free text', 'snapshot_path' => 'retained/history.jpg']);
        RfidScanLog::create(['tag_uid' => 'HISTORICAL-READ', 'scan_location' => $this->archived->code, 'scan_time' => now(),
            'verification_status' => 'unknown_tag', 'fusion_status' => 'scan_only']);
    }

    private function gateOptionValues(string $html, string $name): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $nodes = (new \DOMXPath($dom))->query('//select[@name="'.$name.'"]/option');

        return array_map(fn ($node) => $node->getAttribute('value'), iterator_to_array($nodes));
    }

    public function test_normal_filter_options_hide_archived_but_include_inactive_and_preserve_operational_rules(): void
    {
        $this->assertArrayHasKey($this->inactive->code, Gate::filterOptions());
        $this->assertArrayNotHasKey($this->archived->code, Gate::filterOptions());
        $this->assertArrayNotHasKey($this->inactive->code, Gate::options());
        $this->assertArrayNotHasKey($this->archived->code, Gate::options());
        $this->assertArrayHasKey($this->archived->code, Gate::historicalOptions());
        foreach ([route('logs.index'), route('visitors.index'), route('logs.index', ['tab' => 'scans'])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $options = $this->gateOptionValues($html, str_contains($url, 'scans') ? 'scan_location' : 'gate');
            $this->assertNotContains($this->archived->code, $options);
            $this->assertContains($this->inactive->code, $options);
        }
        $this->get(route('settings.index', ['tab' => 'gates']))->assertOk()->assertDontSee('gates['.$this->archived->code.'][name]', false);
        $this->get(route('gates.kiosk', $this->archived->code))->assertNotFound();
    }

    public function test_all_gates_history_totals_details_exports_and_names_are_retained_without_row_changes(): void
    {
        $before = [$this->event->fresh()->getRawOriginal(), $this->guest->fresh()->getRawOriginal(), $this->camera->fresh()->getRawOriginal()];
        $this->getJson(route('logs.index'))->assertOk()->assertJsonPath('total', 3);
        $this->assertSame(2, app(MovementCountService::class)->counts('today')['in']);
        $this->get(route('vehicle-events.show', $this->event))->assertOk()->assertSee('Historical camera');
        $csv = $this->get(route('vehicle-events.export.csv', ['all' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Old history gate', $csv);
        $this->assertStringContainsString('Unregistered Guest', $csv);
        $this->get(route('logs.print', ['autoprint' => 0]))->assertOk()->assertSee('Old history gate')->assertSee('Unregistered Guest');
        $this->assertSame($before, [$this->event->fresh()->getRawOriginal(), $this->guest->fresh()->getRawOriginal(), $this->camera->fresh()->getRawOriginal()]);
    }

    public function test_old_bookmarks_keep_a_clear_read_only_archived_criterion_without_reinserting_an_option(): void
    {
        foreach ([[route('logs.index'), 'gate'], [route('visitors.index'), 'gate'], [route('logs.index', ['tab' => 'scans']), 'scan_location']] as [$url, $name]) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query([$name => $this->archived->code]);
            $response = $this->get($url)->assertOk()->assertSee('Historical filter: Old history gate (archived). Past records only.')
                ->assertSee('Change gate')->assertSee('Reset');
            $this->assertNotContains($this->archived->code, $this->gateOptionValues($response->getContent(), $name));
            $response->assertSee('name="'.$name.'" value="'.$this->archived->code.'"', false);
        }
        $this->getJson(route('logs.index', ['gate' => $this->archived->code, 'plate_text' => 'HST9001']))
            ->assertOk()->assertJsonPath('total', 1);
        $this->get(route('vehicle-events.export.csv', ['all' => 1, 'gate' => $this->archived->code]))->assertOk();
        $this->getJson(route('logs.index'))->assertOk()->assertJsonPath('total', 3);
    }

    public function test_crafted_operational_requests_cannot_use_archived_gates_or_calibration(): void
    {
        $this->postJson(route('visitors.records.store'), ['gate' => $this->archived->code, 'direction' => 'IN', 'seen_at' => now()->toDateTimeString()])
            ->assertUnprocessable()->assertJsonValidationErrors('gate');
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.crossings'), [
            'external_event_key' => 'forbidden-archive', 'camera_role' => $this->archived->code, 'direction' => 'IN', 'event_time' => now()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('camera_role');
        $this->putJson(route('settings.update'), ['section' => 'timing', 'pass_min_move' => [$this->archived->code => 45]])
            ->assertUnprocessable()->assertJsonValidationErrors('pass_min_move');
        $this->putJson(route('settings.update'), ['section' => 'cameras', 'camera_configs' => [],
            'camera_streams' => [$this->archived->code => ['stream' => 'sub', 'snapshots' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors('camera_streams');
        $reader = NetworkDevice::create(['device_key' => 'phase5-reader', 'name' => 'Unassigned reader', 'ip' => '192.0.2.4', 'kind' => 'rfid_reader']);
        $this->postJson(route('settings.devices.assign', $reader), ['station' => $this->archived->code, 'role' => 'reader'])
            ->assertUnprocessable();
        $mask = $this->camera->calibration_mask_json;
        $this->putJson(route('calibration.update'), ['camera_id' => $this->camera->id])->assertUnprocessable();
        $this->assertSame($mask, $this->camera->fresh()->calibration_mask_json);
        $this->assertDatabaseMissing('device_assignments', ['station' => $this->archived->code]);
        $this->assertSame(29, $this->archived->fresh()->pass_min_move);
        $this->assertDatabaseMissing('vehicle_crossings', ['external_event_key' => 'forbidden-archive']);
    }

    public function test_historical_edit_can_keep_its_original_archived_gate_but_cannot_reassign_another_record_to_it(): void
    {
        $old = GuestVehicleObservation::create(['plate_number' => 'OLD-0011', 'location' => $this->archived->code,
            'vehicle_type' => 'Car', 'observed_at' => now(), 'status' => 'pending_review', 'camera_id' => $this->camera->id,
            'snapshot_path' => 'retained/history.jpg', 'notes' => 'Visitor supplied note']);
        $input = ['plate_number' => 'OLD-0011', 'location' => $this->archived->code, 'vehicle_type' => 'Car',
            'observed_at' => now()->toDateTimeString(), 'status' => 'reviewed', 'notes' => 'Visitor supplied note'];
        $this->patchJson(route('guest-observations.update', $old), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->archived->code, $old->fresh()->location);
        $this->assertSame($this->camera->id, $old->fresh()->camera_id);
        $this->assertSame('retained/history.jpg', $old->fresh()->snapshot_path);
        $this->patchJson(route('guest-observations.verify', $old), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->archived->code, $old->fresh()->location);
        $new = GuestVehicleObservation::create(['plate_number' => 'NEW-0012', 'location' => 'gate-1', 'vehicle_type' => 'Car', 'observed_at' => now(), 'status' => 'pending_review']);
        $this->patchJson(route('guest-observations.update', $new), $input)->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->assertSame('gate-1', $new->fresh()->location);
    }

    public function test_inactive_gate_still_counts_toward_minimum_and_ids_are_not_renumbered(): void
    {
        $ids = Gate::query()->pluck('id', 'code')->all();
        $this->delete(route('settings.gates.destroy', 'gate-1'), ['confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(2, Gate::query()->retained()->count());
        $this->assertArrayHasKey($this->inactive->code, Gate::filterOptions());
        $this->delete(route('settings.gates.destroy', $this->inactive->code), ['confirm' => '1'])
            ->assertSessionHasErrors(['gate' => 'At least two gates are required.']);
        $this->assertSame($ids, Gate::query()->pluck('id', 'code')->all());
        $this->assertNull($this->inactive->fresh()->archived_at);
    }

    public function test_guest_labels_do_not_change_routes_codes_classification_or_historical_free_text(): void
    {
        $this->assertSame('registered_visitor', VehicleCategory::REGISTERED_VISITOR);
        $this->assertSame('unregistered_visitor', VehicleCategory::UNREGISTERED_VISITOR);
        $this->assertSame('Registered Guest', VehicleCategory::label('registered_visitor'));
        $this->assertSame('Unregistered Guest', VehicleCategory::label('guest'));
        $this->get(route('visitors.index'))->assertOk()->assertSee('Guests')->assertSee('Save guest');
        $this->get('/guests')->assertRedirect(route('visitors.index'));
        $this->getJson(route('logs.index', ['log_type' => 'unregistered_visitor']))->assertOk()->assertJsonPath('logs.0.record_type', 'visitor_record');
        $this->assertSame('guest', $this->guest->fresh()->registration_status);
        $this->assertSame('Visitor wording supplied in historical free text', $this->guest->fresh()->status_note);
    }
}
