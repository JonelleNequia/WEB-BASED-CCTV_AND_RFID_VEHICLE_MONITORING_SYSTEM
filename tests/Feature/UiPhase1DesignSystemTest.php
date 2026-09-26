<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DisplayTime;
use App\Support\StatusBadge;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * UI Phase 1: shared components, one badge system, one date format,
 * compact headers and toasts on every admin page.
 */
class UiPhase1DesignSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    public function test_admin_pages_use_the_compact_header_without_hero_or_info_icons(): void
    {
        foreach ([
            'dashboard.index', 'vehicle-registry.index', 'rfid-inventory.index', 'rfid-scans.index',
            'guest-passes.index', 'guest-observations.index', 'vehicle-events.index', 'vehicle-events.create',
            'settings.index', 'calibration.index', 'system-status.index',
        ] as $route) {
            $this->actingAs($this->admin)
                ->get(route($route))
                ->assertOk()
                ->assertSee('class="page-header"', false)
                ->assertDontSee('hero-panel', false)
                ->assertDontSee('help-popover', false)
                ->assertDontSee('Offline Local System')
                ->assertSee('js/ui.js', false);
        }
    }

    public function test_one_date_format_in_manila_time(): void
    {
        $utc = Carbon::parse('2026-09-26T04:45:07Z');

        $this->assertSame('Sep 26, 2026 · 12:45 PM', DisplayTime::datetime($utc));
        $this->assertSame('Sep 26, 2026 · 12:45:07 PM', DisplayTime::datetimeSeconds($utc));
        $this->assertSame('Sep 26, 2026', DisplayTime::date($utc));
        $this->assertSame('12:45 PM', DisplayTime::time($utc));
        $this->assertSame('No scan yet', DisplayTime::datetime(null, 'No scan yet'));

        $html = Blade::render('<x-datetime :value="$value" />', ['value' => $utc]);
        $this->assertStringContainsString('Sep 26, 2026 · 12:45 PM', $html);
        $this->assertStringContainsString('datetime="2026-09-26T12:45:07+08:00"', $html);
    }

    public function test_status_badges_share_one_tone_system(): void
    {
        foreach (['Lost', 'Anomaly', 'Overstay', 'alert'] as $status) {
            $this->assertSame('critical', StatusBadge::tone($status), $status);
        }

        $this->assertSame('success', StatusBadge::tone('Inside'));
        $this->assertSame('success', StatusBadge::tone('Active'));
        $this->assertSame('success', StatusBadge::tone('Assigned'));
        $this->assertSame('brand', StatusBadge::tone('Issued'));
        $this->assertSame('neutral', StatusBadge::tone('Outside'));
        $this->assertSame('neutral', StatusBadge::tone('Available'));

        $html = Blade::render('<x-badge status="lost" />');
        $this->assertStringContainsString('badge-tone-critical', $html);
        $this->assertStringContainsString('Lost', $html);
    }

    public function test_components_render(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-page-header title="Registry"><x-slot:actions><button>Add</button></x-slot:actions></x-page-header>
            <x-stat label="Inside" :value="3" metric="vehicles_inside" />
            <x-tabs :tabs="['vehicles' => 'Vehicles', 'tags' => ['label' => 'RFID Tags', 'count' => 4]]" active="tags" />
            <x-drawer id="demo-drawer" title="Add Vehicle">form</x-drawer>
            <x-table :empty="true" empty-title="No records yet"><x-slot:emptyAction><button>Add Vehicle</button></x-slot:emptyAction></x-table>
            BLADE);

        $this->assertStringContainsString('<h1>Registry</h1>', $html);
        $this->assertStringContainsString('data-dashboard-metric="vehicles_inside"', $html);
        $this->assertStringContainsString('?tab=tags', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('No records yet', $html);
        $this->assertStringContainsString('Add Vehicle', $html);
    }

    public function test_session_messages_become_toasts(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['status' => 'Vehicle saved.'])
            ->get(route('vehicle-registry.index'))
            ->assertSee('data-initial-toasts', false)
            ->assertSee('Vehicle saved.')
            ->assertDontSee('alert alert-success', false);
    }
}
