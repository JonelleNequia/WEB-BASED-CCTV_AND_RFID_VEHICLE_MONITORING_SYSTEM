<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VisitorRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class RegistryGuestReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        Storage::fake('public');
    }

    private function record(array $attributes = []): VisitorRecord
    {
        return VisitorRecord::create($attributes + ['external_event_key' => 'report-'.\Illuminate\Support\Str::uuid(),
            'seen_at' => now()->subDay(), 'gate' => 'gate-2', 'direction' => 'IN', 'source' => 'manual',
            'plate_number' => 'MATCH 001', 'plate_status' => 'corrected', 'registration_status' => 'guest', 'status' => 'active']);
    }

    private function csv(string $url): array
    {
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    public static function lists(): array
    {
        return [['vehicles'], ['tags'], ['guests']];
    }

    #[DataProvider('lists')]
    public function test_reports_include_all_matching_records_in_list_order_with_full_summaries(string $dataset): void
    {
        $ids = [];
        foreach (range(1, 65) as $index) {
            if ($dataset === 'vehicles') {
                $tag = $index % 3 ? RfidTag::create(['uid' => '000'.str_pad((string) $index, 6, '0', STR_PAD_LEFT), 'status' => 'assigned', 'tag_number' => $index]) : null;
                $vehicle = Vehicle::create(['plate_number' => 'MATCH '.str_pad((string) $index, 4, '0', STR_PAD_LEFT), 'category' => 'faculty_staff', 'vehicle_type' => 'Car', 'rfid_tag_id' => $tag?->id]);
                $vehicle->forceFill(['status' => 'active', 'current_state' => 'OUTSIDE'])->save();
                $ids[] = $vehicle->id;
            } elseif ($dataset === 'tags') {
                $ids[] = RfidTag::create(['uid' => '000'.str_pad((string) $index, 6, '0', STR_PAD_LEFT), 'status' => 'available', 'tag_number' => $index])->id;
            } else {
                $ids[] = $this->record(['plate_number' => 'MATCH '.str_pad((string) $index, 4, '0', STR_PAD_LEFT)])->id;
            }
        }
        $filters = match ($dataset) {
            'vehicles' => ['tab' => 'vehicles', 'q' => 'MATCH', 'category' => 'faculty_staff', 'state' => 'outside', 'status' => 'active'],
            'tags' => ['tab' => 'tags', 'status' => 'available'],
            default => ['tab' => 'records', 'record_filter' => 'manual', 'q' => 'match-00', 'gate' => 'gate-2', 'plate_status' => 'corrected', 'status' => 'active'],
        };
        if ($dataset === 'vehicles') {
            Vehicle::create(['plate_number' => 'OTHER 123', 'category' => 'student']);
        } elseif ($dataset === 'tags') {
            RfidTag::create(['uid' => 'EXCLUDED', 'status' => 'lost']);
        } else {
            $this->record(['source' => 'camera']);
            $this->record(['status' => 'dismissed']);
        }
        $prefix = $dataset === 'guests' ? '/visitors' : '/registry';
        $list = $this->get($prefix.'?'.http_build_query($filters))->assertOk();
        $key = match ($dataset) {
            'vehicles' => 'vehicles', 'tags' => 'rfidTagInventory', default => 'records'
        };
        $listIds = $list->viewData($key)->pluck('id')->all();
        if ($dataset === 'guests') {
            foreach (range(2, 4) as $page) {
                $listIds = [...$listIds, ...$this->get($prefix.'?'.http_build_query($filters + ['page' => $page]))->assertOk()->viewData('records')->pluck('id')->all()];
            }
        }
        $report = $this->get($prefix.'/print?'.http_build_query($filters + ['page' => 2]))->assertOk();
        $rows = $report->viewData('report')['rows'];
        $this->assertSame($listIds, $rows->pluck('id')->all());
        $this->assertCount(65, array_unique($listIds));
        $csv = $this->csv($prefix.'/export/csv?'.http_build_query($filters + ['page' => 3]));
        $this->assertSame($listIds, array_map('intval', array_column(array_slice($csv, 1), 0)));
        foreach ($rows->values() as $index => $row) {
            $this->assertSame(array_values($row['cells']), $csv[$index + 1]);
        }
        foreach ($report->viewData('report')['summaries'] as $summary) {
            $this->assertSame(65, array_sum($summary));
        }
        if ($dataset === 'vehicles') {
            $report->assertSee('No RFID assigned')->assertSee('Outside')->assertSee('Active');
        }
    }

    public function test_guests_quick_filters_show_and_archived_gate_history_keep_phase_five_meaning(): void
    {
        $vehicle = Vehicle::create(['plate_number' => '395ISX', 'category' => 'faculty_staff']);
        $guest = $this->record(['vehicle_id' => $vehicle->id, 'plate_number' => '395ISX', 'source' => 'camera']);
        $registered = $this->record(['registration_status' => 'registered', 'registration_category' => 'faculty_staff', 'vehicle_id' => $vehicle->id]);
        $manual = $this->record();
        $this->record(['registration_status' => 'review', 'source' => 'camera']);
        $duplicate = $this->record(['status' => 'duplicate']);
        Gate::where('code', 'gate-2')->update(['archived_at' => now()]);
        foreach (['registered' => [$registered->id], 'unregistered' => [$manual->id, $guest->id], 'manual' => [$manual->id, $registered->id]] as $filter => $ids) {
            $query = ['record_filter' => $filter, 'gate' => 'gate-2'];
            $rows = $this->get('/visitors/print?'.http_build_query($query))->assertOk()->viewData('report')['rows'];
            $this->assertEqualsCanonicalizing($ids, $rows->pluck('id')->all());
            $csv = $this->csv('/visitors/export/csv?'.http_build_query($query));
            $this->assertSame($rows->pluck('id')->all(), array_map('intval', array_column(array_slice($csv, 1), 0)));
        }
        $this->assertSame([$duplicate->id], $this->get('/visitors/print?record_filter=manual&status=duplicate')->assertOk()->viewData('report')['rows']->pluck('id')->all());
    }

    public function test_csv_preserves_literal_identifiers_unicode_escaping_and_neutralizes_formulas(): void
    {
        $tag = RfidTag::create(['uid' => '0000123000', 'status' => 'assigned', 'tag_number' => 1]);
        $vehicle = Vehicle::create(['plate_number' => '001ABC', 'category' => 'faculty_staff', 'rfid_tag_id' => $tag->id,
            'vehicle_owner_name' => "Ma. José, O’Connor\n\"Quoted\"", 'registered_by' => " \t=HYPERLINK(\"bad\")"]);
        $this->record(['plate_number' => '395ISX']);
        $this->record(['plate_number' => 'ISX395']);
        $this->record(['plate_number' => 'O0I1']);
        $rows = $this->csv('/registry/export/csv?tab=vehicles');
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame('001ABC', $row['Plate']);
        $this->assertSame('0000123000', $row['RFID UID']);
        $this->assertSame($vehicle->vehicle_owner_name, $row['Owner']);
        $this->assertSame("'".$vehicle->registered_by, $row['Registered by']);
        $guestRows = $this->csv('/visitors/export/csv');
        $this->assertEqualsCanonicalizing(['395ISX', 'ISX395', 'O0I1'], array_column(array_slice($guestRows, 1), 2));
        foreach (['=1+1', '+1', '-1', '@SUM(1)', "\ttext", "\rtext", "\ntext", '   =1'] as $text) {
            $vehicle->forceFill(['vehicle_owner_name' => $text])->save();
            $rows = $this->csv('/registry/export/csv');
            $this->assertSame("'".$text, array_combine($rows[0], $rows[1])['Owner']);
        }
    }

    public function test_print_metadata_options_images_and_back_link_use_the_applied_filters(): void
    {
        config(['monitoring.report.school_name' => 'Test School', 'monitoring.report.system_name' => 'Test Monitoring', 'app.timezone' => 'UTC']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-05 17:05:00', 'UTC'));
        Storage::disk('public')->put('snapshots/linked.png', 'fixture');
        $record = $this->record(['snapshot_path' => 'snapshots/linked.png']);
        $missing = $this->record(['snapshot_path' => 'snapshots/missing.png']);
        $query = ['record_filter' => 'manual', 'gate' => 'gate-2', 'snapshots' => 1];
        $response = $this->get('/visitors/print?'.http_build_query($query))->assertOk();
        $response->assertSee('Test School')->assertSee('Test Monitoring')->assertSee('Nov 6, 2026 · 1:05 AM')->assertSee(auth()->user()->name)
            ->assertSee('Include images')->assertSee('No image')->assertSee($record->snapshot_url)->assertSee('Save as PDF');
        $this->assertStringContainsString('record_filter=manual', $response->viewData('backUrl'));
        $this->assertStringContainsString('gate=gate-2', $response->viewData('backUrl'));
        $this->assertNull($response->viewData('report')['rows']->firstWhere('id', $missing->id)['image_url']);
        $this->get('/visitors/print?record_filter=manual')->assertOk()->assertDontSee('<img ', false);
        $this->get('/registry/print?tab=vehicles')->assertOk()->assertDontSee('Include images');
        $this->get('/registry/print?tab=tags')->assertOk()->assertDontSee('Include images');
    }

    #[DataProvider('lists')]
    public function test_empty_reports_and_csv_have_clear_headers_and_no_rows(string $dataset): void
    {
        $prefix = $dataset === 'guests' ? '/visitors' : '/registry';
        $filters = match ($dataset) {
            'vehicles' => ['q' => 'MISSING'], 'tags' => ['tab' => 'tags', 'status' => 'lost'], default => ['q' => 'MISSING']
        };
        $this->get($prefix.'/print?'.http_build_query($filters))->assertOk()->assertSee('No records match these filters.');
        $this->assertCount(1, $this->csv($prefix.'/export/csv?'.http_build_query($filters)));
    }

    public function test_direct_requests_validate_the_same_filters_and_report_options(): void
    {
        foreach (['/registry/print?state=wrong', '/registry/export/csv?status=wrong', '/registry/print?tab=wrong',
            '/registry/export/csv?tab=tags&status=wrong', '/visitors/print?record_filter=wrong', '/visitors/export/csv?status=wrong',
            '/visitors/print?tab=plates', '/visitors/print?snapshots[]=1', '/visitors/export/csv?gate=missing'] as $url) {
            $this->get($url)->assertUnprocessable()->assertSee('The report was not generated.');
            $this->getJson($url)->assertUnprocessable()->assertJsonStructure(['errors']);
        }
    }

    public function test_export_actions_use_the_auto_filter_link_contract_and_list_permissions(): void
    {
        foreach (['/registry', '/registry?tab=tags', '/visitors'] as $url) {
            $this->get($url)->assertOk()->assertSee('data-auto-link="list-print"', false)->assertSee('data-auto-link="list-csv"', false)->assertSee('data-auto-export', false);
        }
        $this->actingAs(User::factory()->create(['role' => 'guard']));
        foreach (['/registry/print', '/registry/export/csv', '/registry/print?tab=tags', '/registry/export/csv?tab=tags'] as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach (['/visitors/print', '/visitors/export/csv'] as $url) {
            $this->get($url)->assertOk();
        }
        auth()->logout();
        foreach (['/registry/print', '/registry/export/csv', '/visitors/print', '/visitors/export/csv'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }
}
