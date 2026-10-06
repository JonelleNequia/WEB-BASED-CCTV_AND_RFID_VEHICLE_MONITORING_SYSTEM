<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Services\CameraProbeService;
use App\Services\DeviceRegistryService;
use App\Services\SettingsService;
use App\Support\CameraFiles;
use App\Support\DeviceFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Live-latency work: stream roles, tuning export, pipeline metrics, and the
 * confirmed ONVIF camera optimization. Addresses from RFC 5737.
 */
class LiveLatencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    public array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        File::deleteDirectory(DeviceFiles::directory());
        $test = $this;

        $this->app->instance(CameraProbeService::class, new class($test) extends CameraProbeService
        {
            public function __construct(private $test)
            {
            }

            public function describe(string $url, string $username = '', string $password = ''): array
            {
                return ['result' => self::OK, 'status' => 200, 'message' => 'ok'];
            }

            public function onvifStreams(string $deviceServiceUrl, string $username, string $password): array
            {
                return ['result' => self::ERROR, 'streams' => [], 'message' => 'no'];
            }

            public function encoderConfigurations(string $deviceServiceUrl, string $username, string $password): array
            {
                return ['result' => self::OK, 'message' => 'ok', 'media_url' => 'http://198.51.100.20/onvif/service', 'encoders' => [
                    ['token' => 'main', 'name' => 'Main', 'encoding' => 'H264', 'width' => 2560, 'height' => 1440, 'fps' => 25, 'bitrate' => 3584, 'gov' => 25, 'profile' => 'Main', 'xml' => ''],
                    ['token' => 'minor', 'name' => 'Sub', 'encoding' => 'H264', 'width' => 736, 'height' => 416, 'fps' => 15, 'bitrate' => 768, 'gov' => 15, 'profile' => 'Main', 'xml' => ''],
                    ['token' => 'jpeg', 'name' => 'Jpeg', 'encoding' => 'JPEG', 'width' => 640, 'height' => 360, 'fps' => 1, 'bitrate' => 512, 'gov' => null, 'profile' => null, 'xml' => ''],
                ]];
            }

            public function setEncoderConfiguration(string $mediaUrl, array $encoder, array $changes, string $username, string $password): bool
            {
                $this->test->writes[] = [$encoder['token'], $changes];

                return true;
            }
        });

        app(DeviceRegistryService::class)->ingestScan(['scan' => ['complete' => true], 'devices' => [[
            'key' => '34:F7:16:00:00:01', 'mac' => '34:F7:16:00:00:01', 'ip' => '198.51.100.20', 'reachable' => true,
            'kind' => 'camera', 'confidence' => 'confirmed', 'name' => 'VIGI C240',
            'camera' => ['rtsp_port' => 554, 'onvif_xaddr' => 'http://198.51.100.20:80/onvif/device_service', 'vendor_profile' => 'TP-Link VIGI', 'rtsp_paths' => ['main' => '/stream1', 'sub' => '/stream2']],
        ]]]);
        $device = NetworkDevice::query()->firstOrFail();
        app(DeviceRegistryService::class)->assign($device, 'gate-1', 'camera', ['username' => 'admin', 'password' => 'secret']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(DeviceFiles::directory());
        File::delete(CameraFiles::statusPath());

        parent::tearDown();
    }

    public function test_sub_stream_live_with_main_stream_snapshots_and_tuning_export(): void
    {
        $runtime = fn () => json_decode(File::get(app(SettingsService::class)->cameraRuntimeConfigPath()), true);

        $entrance = $runtime()['cameras']['gate-1'];
        $this->assertSame('rtsp://198.51.100.20:554/stream2', $entrance['source_value']);
        $this->assertSame('rtsp://198.51.100.20:554/stream1', $entrance['snapshot_source_value']);
        $this->assertSame(1, $entrance['decoder_threads']);
        $this->assertSame(15.0, (float) $runtime()['system_settings']['performance']['stream_fps']);

        // Settings › Cameras: main stream live (no separate snapshots), new tuning.
        $this->actingAs($this->admin)->put(route('settings.update'), [
            'section' => 'cameras',
            'camera_configs' => [
                'gate-1' => ['camera_name' => 'Entrance Camera', 'source_type' => 'rtsp', 'source_value' => $entrance['source_value'], 'source_username' => 'admin', 'source_password' => ''],
                'gate-2' => ['camera_name' => 'Exit Camera', 'source_type' => 'webcam', 'source_value' => '0', 'source_username' => '', 'source_password' => ''],
            ],
            'camera_streams' => ['gate-1' => ['stream' => 'main', 'snapshots' => '1']],
            // Live view work: tuning is no longer a setting; it is ignored.
            'perf_stream_fps' => 12, 'perf_stream_width' => 800,
        ])->assertSessionHasNoErrors();

        $entrance = $runtime()['cameras']['gate-1'];
        $this->assertSame('rtsp://198.51.100.20:554/stream1', $entrance['source_value']);
        $this->assertSame('', $entrance['snapshot_source_value'], 'Main stream live: no second connection to the same stream.');
        $this->assertSame(0, $entrance['decoder_threads']);
        $this->assertSame('main', DeviceAssignment::query()->where('station', 'gate-1')->value('options')['stream']);
        $performance = $runtime()['system_settings']['performance'];
        // Live view work: proven defaults from config/monitoring.php (detection).
        $this->assertSame([15.0, 960, 75, 8.0, 480, 'auto', 1], [
            (float) $performance['stream_fps'], $performance['stream_width'], $performance['jpeg_quality'],
            (float) $performance['detection_fps'], $performance['yolo_imgsz'], $performance['yolo_device'], $performance['roi_crop'],
        ]);

        // Live view work: the Detection page is gone; camera settings stay in Advanced › Manual setup.
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'detection']))->assertOk()->assertDontSee('Live view performance');
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'cameras']))->assertOk()->assertSee('Optimize camera settings');
    }

    public function test_detector_debug_view_switch_is_exported_and_counters_are_shown(): void
    {
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'calibration']))
            ->assertOk()->assertSee('Turn on debug view');

        $this->actingAs($this->admin)->post(route('calibration.debug'), ['enabled' => 1])->assertRedirect();
        $config = json_decode(File::get(CameraFiles::path('camera_runtime_config.json')), true);
        $this->assertSame(1, $config['system_settings']['performance']['debug_overlay']);
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'calibration']))
            ->assertOk()->assertSee('Debug view is ON');

        $this->actingAs($this->admin)->postJson(route('calibration.debug'), ['enabled' => 0])->assertOk()->assertJsonPath('enabled', false);
        $config = json_decode(File::get(CameraFiles::path('camera_runtime_config.json')), true);
        $this->assertSame(0, $config['system_settings']['performance']['debug_overlay']);

        File::ensureDirectoryExists(CameraFiles::directory());
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'cameras' => ['gate-1' => [
                'camera_running' => true, 'detection_ready' => true, 'last_error' => '',
                'detection' => ['detection_fps' => 7.8, 'device' => 'mps', 'last_raw_detections' => 4, 'last_vehicles' => 3, 'last_in_zone' => 2, 'line_crossings' => 5],
            ]],
        ]));
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'status']))
            ->assertOk()->assertSee('7.8 on mps')->assertSee('4 / 3 / 2');
    }

    public function test_status_page_shows_the_measured_pipeline(): void
    {
        File::ensureDirectoryExists(CameraFiles::directory());
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'cpu' => ['process' => 26.5, 'system' => 15.6, 'cores' => 8],
            'cameras' => ['gate-1' => ['camera_running' => true], 'gate-2' => ['camera_running' => true]],
            'metrics' => [
                'gate-1' => [
                    'fps' => ['capture' => 25.0, 'stream_published' => 15.0, 'stream_sent' => 15.0, 'detection' => 7.6],
                    'ms' => ['encode' => ['avg' => 1.2, 'p95' => 1.8], 'yolo' => ['avg' => 23.9, 'p95' => 29.5], 'pipeline' => ['avg' => 2.3, 'p95' => 4.6]],
                    'values' => ['resolution' => '736x416', 'decoder_threads' => 1, 'decode_backlog_ms' => -3, 'yolo_device' => 'mps'],
                ],
                'gate-2' => [
                    'fps' => ['capture' => 25.0],
                    'ms' => ['pipeline' => ['avg' => 2.0, 'p95' => 4.0]],
                    'values' => ['resolution' => '2560x1440', 'decoder_threads' => 'auto', 'decode_backlog_ms' => 900],
                ],
            ],
        ]));

        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'status']))
            ->assertOk()
            // UI Phase 3: one line per gate on top, the measurements in "Advanced diagnostics".
            ->assertSee('Live video')
            ->assertSeeInOrder(['Gate 1', 'Live view about 2 ms behind.', 'Delayed', 'Gate 2', 'decoding falls behind the camera by 900 ms.', 'Advanced diagnostics'])
            ->assertDontSee('No delay on this PC')
            ->assertSee('736x416 · 1 thread (low delay)')
            ->assertSee('Gate 2: decoding falls behind the camera by 900 ms')
            ->assertSee('frame threads, which hold frames back');

        $this->actingAs($this->admin)->get(route('settings.status.metrics'))->assertOk()->assertSee('data-pipeline-metrics', false);
    }

    public function test_camera_optimization_previews_then_writes_only_after_the_request(): void
    {
        $this->actingAs($this->admin)->getJson(route('settings.cameras.encoder', 'gate-1'))
            ->assertOk()
            ->assertJsonCount(2, 'encoders') // JPEG stream left alone
            ->assertJsonPath('encoders.0.current.fps', 25)
            ->assertJsonPath('encoders.0.proposed', ['fps' => 15, 'gov' => 15, 'bitrate' => 3072]);
        $this->assertSame([], $this->writes, 'Previewing must not change the camera.');

        $this->actingAs($this->admin)->postJson(route('settings.cameras.encoder.optimize', 'gate-1'))
            ->assertOk()
            ->assertJsonPath('ok', true);

        // Only the main stream differed from the recommendation.
        $this->assertSame([['main', ['fps' => 15, 'gov' => 15, 'bitrate' => 3072]]], $this->writes);

        $this->actingAs($this->admin)->getJson(route('settings.cameras.encoder', 'gate-2'))
            ->assertStatus(422);
    }
}
