<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleEvent;
use App\Services\SettingsService;
use App\Support\OccurrenceMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class DeploymentHttpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        Storage::fake('public');
        Storage::fake('occurrences');
    }

    private function admin(): User { return User::where('email', 'admin@philcst.local')->firstOrFail(); }
    private function origin(): void { config(['deployment.named_https' => true, 'session.secure' => true]); }
    private function image(string $path): void
    {
        Storage::disk('public')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lp8AAAAASUVORK5CYII='));
    }
    private function reference(string $path): VehicleEvent
    {
        return VehicleEvent::create(['event_status' => 'completed', 'event_type' => 'ENTRY', 'event_origin' => 'manual', 'event_time' => now(), 'vehicle_image_path' => $path]);
    }

    public function test_named_origin_rejects_foreign_hosts_plain_http_and_spoofed_forwarded_headers(): void
    {
        $this->origin();
        $this->get('https://philcst.vms.internal/login')->assertOk();
        foreach (['http://philcst.vms.internal/login', 'https://attacker.invalid/login', 'https://philcst.vms.internal:8443/login'] as $url) {
            $this->get($url)->assertStatus(400);
        }
        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'philcst.vms.internal'])
            ->get('http://attacker.invalid/login')->assertStatus(400);
    }

    public function test_collectors_use_loopback_keyed_integration_not_public_browser_routes(): void
    {
        $this->origin();
        $this->getJson('https://philcst.vms.internal/api/v1/integration/status')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->getJson('http://127.0.0.1:8080/api/v1/integration/status')->assertUnauthorized();
        $this->withHeader('X-Api-Key', 'test-detector-key')->getJson('http://127.0.0.1:8080/api/v1/integration/status')->assertOk();
        $this->get('http://127.0.0.1:8080/login')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.44.22'])->getJson('http://127.0.0.1:8080/api/v1/integration/status')->assertStatus(400);
        config(['monitoring.integration_url' => 'http://127.0.0.1:8080']);
        $this->assertSame('http://127.0.0.1:8080', app(SettingsService::class)->integrationUrl());
        $this->assertFalse(app(SettingsService::class)->allowsKeylessLocalIntegration());
    }

    public function test_login_setup_redirects_and_session_cookie_use_https_origin(): void
    {
        $this->origin();
        $this->get('https://philcst.vms.internal/setup')->assertNotFound(); // Existing admin closes first setup.
        $response = $this->get('https://philcst.vms.internal/gates');
        $response->assertRedirect('https://philcst.vms.internal/login');
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_historical_reference_is_authenticated_exact_and_unchanged(): void
    {
        $path = 'guest_snapshots/earlier-event.png';$this->image($path);$event = $this->reference($path);$before = $event->fresh()->getRawOriginal();
        $this->assertSame('/storage/'.$path, OccurrenceMedia::url($path));
        $this->get('/storage/'.$path)->assertRedirect('/login');
        $this->actingAs($this->admin())->get('/storage/'.$path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $event->fresh()->getRawOriginal());
        $this->image('guest_snapshots/unreferenced.png');
        $this->get('/storage/guest_snapshots/unreferenced.png')->assertNotFound();
        $this->get('/storage/.env')->assertNotFound();
        $this->assertFalse(OccurrenceMedia::legacyPath('../secret.png'));
        $this->assertFalse(OccurrenceMedia::legacyPath('directory\\secret.png'));
        $this->assertFalse(OccurrenceMedia::legacyPath('https://server/image.png'));
    }

    public function test_historical_symlink_escape_is_denied(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'd4-media-');file_put_contents($outside, 'not public');
        try {
            symlink($outside, Storage::disk('public')->path('escape.png'));
            $this->reference('escape.png');
            $this->actingAs($this->admin())->get('/storage/escape.png')->assertNotFound();
        } finally { unlink($outside); }
    }

    public function test_disabled_stale_password_restricted_and_logged_out_users_cannot_request_media(): void
    {
        $path='guest_snapshots/history.png';$this->image($path);$this->reference($path);
        $admin=$this->admin();$this->actingAs($admin)->getJson('/storage/'.$path)->assertOk();
        $admin->forceFill(['account_status'=>'disabled'])->save();
        $this->getJson('/storage/'.$path)->assertUnauthorized();
        $admin->forceFill(['account_status'=>'active'])->save();$this->actingAs($admin);
        $admin->increment('session_version');$this->getJson('/storage/'.$path)->assertUnauthorized();
        $this->actingAs($admin->fresh());$this->post('/logout')->assertRedirect();
        $this->getJson('/storage/'.$path)->assertUnauthorized();
        $admin->forceFill(['password_policy_version'=>0])->save();$this->actingAs($admin->fresh());
        $this->getJson('/storage/'.$path)->assertForbidden();
    }

    public function test_private_occurrence_files_keep_exact_reference_authorization(): void
    {
        $file='aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.png';Storage::disk('occurrences')->put($file,'synthetic image');
        $this->actingAs($this->admin())->get('/evidence/occurrences/'.$file)->assertNotFound();
        $this->reference(OccurrenceMedia::PREFIX.$file);
        $this->get('/evidence/occurrences/'.$file)->assertOk();
    }

    public function test_hls_proxy_filters_user_control_parameters_and_external_playlist_urls(): void
    {
        Http::fakeSequence()->push("#EXTM3U\nhls/segment.ts?id=safe\n",200,['Content-Type'=>'application/vnd.apple.mpegurl'])
            ->push("#EXTM3U\nhttp://127.0.0.1:1984/secret\n",200);
        $this->actingAs($this->admin())->get('/live/hls/stream.m3u8?src=gate-1&url=http://attacker.invalid')->assertOk();
        Http::assertSent(fn ($request) => ! str_contains($request->url(),'attacker') && ! str_contains($request->url(),'url='));
        $this->get('/live/hls/stream.m3u8?src=gate-1')->assertStatus(502);
        $this->get('/live/hls/api/config')->assertNotFound();
    }

    public function test_fresh_setup_and_personal_login_complete_on_the_canonical_origin(): void
    {
        $this->origin();
        User::query()->delete(); // Isolated RefreshDatabase fixture only.
        $code=app(\App\Services\AccountSecurityService::class)->issueSetup('owner@d4.test','D4 Fixture Owner',initialize:true);
        $this->get('https://philcst.vms.internal/setup')->assertOk()->assertDontSee($code);
        $password='  quiet rivers carry silver boats  ';
        $this->post('https://philcst.vms.internal/setup',['code'=>$code,'password'=>$password,'password_confirmation'=>$password])
            ->assertRedirect('https://philcst.vms.internal/login');
        $this->post('https://philcst.vms.internal/login',['email'=>'owner@d4.test','password'=>$password])->assertRedirect();
        $this->get('https://philcst.vms.internal/admin')->assertOk();
        $this->assertDatabaseCount('users',1);
    }

    public function test_pages_reports_and_available_images_stay_on_the_canonical_origin(): void
    {
        $this->origin();$this->actingAs($this->admin());
        $path='guest_snapshots/d4-report.png';$this->image($path);$this->reference($path);
        foreach (['/gates','/registry','/visitors','/logs','/logs/print?snapshots=1','/registry/print?tab=vehicles','/registry/export/csv?tab=vehicles'] as $path) {
            $response=$this->get('https://philcst.vms.internal'.$path)->assertOk();
            $response->assertDontSee('http://philcst.vms.internal',false)->assertDontSee('http://127.0.0.1:1984',false);
        }
        $this->get('https://philcst.vms.internal/storage/guest_snapshots/d4-report.png')->assertOk();
        $this->actingAs($this->admin())->call('POST','https://philcst.vms.internal/live/gate-1/webrtc',[],[],[],['CONTENT_TYPE'=>'application/sdp'],"v=0\r\nm=video 9 UDP/TLS/RTP/SAVPF 96\r\na=sendonly\r\n")->assertUnprocessable();
    }
}
