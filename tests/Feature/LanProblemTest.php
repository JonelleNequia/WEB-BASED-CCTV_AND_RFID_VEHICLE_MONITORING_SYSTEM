<?php

namespace Tests\Feature;

use App\Services\GateSetupService;
use Tests\TestCase;

/**
 * Devices do not connect because the LAN itself reaches nothing: the gate
 * cards say so in plain words (not "set the camera to DHCP").
 */
class LanProblemTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $interfaces
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    protected function devices(array $interfaces, array $warnings = []): array
    {
        return [
            'service' => ['running' => true],
            'network' => ['interfaces' => $interfaces],
            'diagnostics' => ['warnings' => array_map(fn (string $code): array => ['code' => $code], $warnings)],
        ];
    }

    public function test_no_lan_cable_and_a_cable_where_nothing_answers_are_named(): void
    {
        $setup = app(GateSetupService::class);
        $wifi = ['name' => 'en0', 'kind' => 'wifi', 'ip' => '203.0.113.5', 'gateway' => '203.0.113.1'];

        $this->assertStringContainsString('no LAN cable', $setup->lanProblem($this->devices([$wifi]))['line']);

        // A cable with only a self-assigned address (and an address added by hand), nothing answering.
        $cable = [
            ['name' => 'en7', 'kind' => 'ethernet', 'ip' => '169.254.12.165', 'gateway' => null, 'link_local' => true],
            ['name' => 'en7', 'kind' => 'ethernet', 'ip' => '198.51.100.1', 'gateway' => null, 'link_local' => false],
        ];
        $problem = $setup->lanProblem($this->devices([...$cable, $wifi], ['link_local', 'lan_empty']));
        $this->assertSame('Nothing answers on the LAN cable: no router gives this PC an address.', $problem['line']);
        $this->assertStringContainsString('not WAN / Internet', $problem['next_step']);

        // A router on the cable: no LAN problem.
        $this->assertNull($setup->lanProblem($this->devices([['name' => 'en7', 'kind' => 'ethernet', 'ip' => '198.51.100.2', 'gateway' => '198.51.100.1']])));
        // The device program is not running yet: nothing to say about the LAN.
        $this->assertNull($setup->lanProblem(['service' => ['running' => false]]));
    }
}
