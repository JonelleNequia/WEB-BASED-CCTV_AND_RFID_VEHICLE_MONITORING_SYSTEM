<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI Phase 5: collapsible sidebar, skip link, loading/live states and labels.
 * (Layout width at 1366px is checked in a real browser; see the phase report.)
 */
class UiPhase5PolishTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    public function test_layout_has_skip_link_collapsible_sidebar_and_progress_bar(): void
    {
        $this->actingAs($this->admin)
            ->get(route('registry.index'))
            ->assertOk()
            ->assertSee('<a href="#main-content" class="skip-link">Skip to content</a>', false)
            ->assertSee('id="main-content"', false)
            ->assertSee('data-sidebar-toggle', false)
            ->assertSee('aria-controls="app-sidebar"', false)
            ->assertSee("localStorage.getItem('ui.sidebar')", false)
            ->assertSee('class="nav-progress"', false)
            ->assertSee('title="Registry"', false);
    }

    public function test_live_pages_show_the_live_indicator(): void
    {
        foreach ([route('dashboard.index'), route('gates.index'), route('logs.index')] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('data-live-indicator', false);
        }

        // Page 2 is a snapshot, not live.
        $this->actingAs($this->admin)->get(route('logs.index', ['page' => 2]))->assertDontSee('data-live-indicator', false);
    }

    public function test_previously_unlabeled_inputs_have_labels(): void
    {
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'cameras']))
            ->assertSee('for="gate-1_source_value"', false)
            ->assertSee('id="gate-1_source_value"', false)
            // Camera source work: no USB webcam and no saved browser device.
            ->assertDontSee('browser_device', false)->assertDontSee('Webcam');

        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'test-scan']))
            ->assertSee('<label for="registered_tag_search">', false);
    }
}
