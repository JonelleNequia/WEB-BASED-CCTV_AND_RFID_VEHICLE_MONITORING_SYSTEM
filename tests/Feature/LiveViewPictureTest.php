<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The live view went black or vanished while the camera was connected:
 * the kiosk and Gate Monitor hid the player whenever the DETECTOR's status
 * was down for a moment (and reconnected a working player when it came
 * back), and a frozen WebRTC stream was never noticed. Checked end to end
 * in headless Chrome with a paused test camera; these keep the pieces.
 */
class LiveViewPictureTest extends TestCase
{
    public function test_the_player_notices_a_frozen_picture_and_keeps_the_last_frame(): void
    {
        $player = File::get(public_path('js/live-video.js'));

        $this->assertStringContainsString('const STALL_MS = 8000;', $player);
        $this->assertStringContainsString('hasPicture() {', $player);
        $this->assertStringContainsString("this.emit('live:stalled'", $player);
        $this->assertStringContainsString('this.video.poster = canvas.toDataURL', $player);
        $this->assertStringContainsString("'Reconnecting…'", $player);
    }

    public function test_pages_hide_the_feed_only_when_the_player_has_no_picture(): void
    {
        foreach (['station-kiosk.js', 'gate-monitor.js'] as $script) {
            $code = File::get(public_path('js/'.$script));
            $this->assertStringContainsString('hasPicture?.()', $code, $script);
            $this->assertStringContainsString("'live:stalled'", $code, $script);
            // A working player is never reconnected just because the detector came back.
            $this->assertMatchesRegularExpression('/&& !(frame\?\.liveVideo|player)\?\.hasPicture\?\.\(\)\)/', $code, $script);
        }
    }

    public function test_the_live_view_scripts_are_never_taken_from_an_old_cache(): void
    {
        foreach (['components/live-video.blade.php' => 'live-video', 'stations/show.blade.php' => 'station-kiosk', 'gates/index.blade.php' => 'gate-monitor'] as $view => $script) {
            $this->assertStringContainsString("asset('js/$script.js') }}?v={{ filemtime(public_path('js/$script.js')) }}", File::get(resource_path("views/$view")));
        }
    }
}
