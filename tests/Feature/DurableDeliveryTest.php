<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\VehicleCrossing;
use App\Models\VisitorRecord;
use App\Services\LifecycleBarrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class DurableDeliveryTest extends TestCase
{
    use RefreshDatabase;
    private string $directory;
    private array $identity;

    protected function setUp(): void
    {
        parent::setUp();$this->seed(PortalSeeder::class);
        $this->directory=sys_get_temp_dir().'/d5a-php-'.Str::uuid();
        File::ensureDirectoryExists($this->directory.'/coordination');
        $this->identity=['schema'=>1,'installation'=>(string) Str::uuid(),'generation'=>(string) Str::uuid()];
        File::put($this->directory.'/identity.json',json_encode($this->identity));
        File::put($this->directory.'/maintenance.json',json_encode(['schema'=>1,'phase'=>'running','operation'=>null]));
        $pdo=new \PDO('sqlite:'.$this->directory.'/coordination/barrier.sqlite');$pdo->exec('CREATE TABLE barrier (id INTEGER PRIMARY KEY)');unset($pdo);
        DB::table('delivery_installations')->insert(['id'=>1,'installation'=>$this->identity['installation'],'generation'=>$this->identity['generation']]);
        config(['lifecycle.root'=>$this->directory]);
        Storage::fake('occurrences');Storage::fake('public');
    }
    protected function tearDown(): void
    {
        app(LifecycleBarrier::class)->release();config(['lifecycle.root'=>null]);
        File::deleteDirectory($this->directory);parent::tearDown();
    }
    private function payload(): array
    {
        return ['external_event_key'=>'d5a-fixture-pass','camera_role'=>'gate-1','camera_id'=>Camera::forRole('gate-1')->value('id'),
            'event_time'=>'2026-11-09T10:00:00+08:00','direction'=>'IN','detected_vehicle_type'=>'Car','detection_metadata'=>['rfid_status'=>'no_pass']];
    }
    private function envelope(string $kind,array $payload,array $media=[]): array
    {
        $inventory=[];$encoded=[];
        foreach ($media as $name=>$bytes) { $inventory[$name]=['filename'=>'fixture.png','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];$encoded[$name]=base64_encode($bytes); }
        $raw=json_encode(['schema'=>1,...$this->identity,'kind'=>$kind,'occurrence'=>$payload['external_event_key'] ?? 'rfid-fixture',
            'operation'=>hash('sha256',Str::uuid()),'payload'=>$payload,'media'=>$inventory],JSON_UNESCAPED_SLASHES);
        return ['envelope'=>$raw,'sha256'=>hash('sha256',$raw),'media'=>$encoded];
    }
    private function send(array $data)
    {
        return $this->withHeader('X-Api-Key','test-detector-key')->postJson('/api/v1/integration/deliveries',$data);
    }
    public function test_committed_receipt_retries_have_one_occurrence_same_time_and_same_image(): void
    {
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lp8AAAAASUVORK5CYII=');
        $data=$this->envelope('crossing',$this->payload(),['snapshot'=>$png]);
        $first=$this->send($data)->assertOk()->assertJsonPath('delivery_ack.committed',true)->json();
        $crossing=VehicleCrossing::firstOrFail();$path=$crossing->snapshot_path;
        $this->send($data)->assertOk()->assertJsonPath('delivery_ack',$first['delivery_ack'])->assertJsonPath('ack_effect','verified')->assertJsonPath('duplicate',true);
        $this->assertDatabaseCount('delivery_receipts',1);$this->assertDatabaseCount('vehicle_crossings',1);$this->assertDatabaseCount('visitor_records',1);
        $this->assertSame($path,$crossing->fresh()->snapshot_path);$this->assertNotNull($path);
        $this->assertSame($png,Storage::disk('occurrences')->get(basename($path)));
        $this->assertSame(\Illuminate\Support\Carbon::parse($this->payload()['event_time'])->timestamp,$crossing->fresh()->crossed_at->timestamp);
    }
    public function test_changed_content_with_same_operation_is_rejected_without_effect(): void
    {
        $data=$this->envelope('crossing',$this->payload());$this->send($data)->assertOk();
        $changed=json_decode($data['envelope'],true);$changed['payload']['direction']='OUT';$data['envelope']=json_encode($changed);$data['sha256']=hash('sha256',$data['envelope']);
        $this->send($data)->assertConflict();$this->assertDatabaseCount('vehicle_crossings',1);$this->assertSame('IN',VehicleCrossing::first()->direction);
    }
    public function test_later_evidence_uses_original_context_and_retries_do_not_replace_staff_confirmation(): void
    {
        $pass=$this->payload();$this->send($this->envelope('crossing',$pass))->assertOk();
        $plate=$this->envelope('plate',[...$pass,'plate_status'=>'read','plate_number'=>'395ISX','plate_confidence'=>.96]);
        $this->send($plate)->assertOk();$record=VisitorRecord::firstOrFail();
        $staff=\App\Models\User::where('role','admin')->firstOrFail();
        app(\App\Services\VisitorRecordService::class)->correctPlate($record,'ISX395',$staff);
        $this->send($plate)->assertOk();
        $this->assertSame('ISX395',\App\Support\PlateNumber::key($record->fresh()->plate_number));$this->assertDatabaseCount('vehicle_crossings',1);
        $this->assertSame($pass['camera_id'],$record->fresh()->camera_id);
    }
    public function test_archived_or_reassigned_context_is_not_rewritten_to_make_pending_work_fit(): void
    {
        $data=$this->envelope('crossing',$this->payload());
        Camera::whereKey($this->payload()['camera_id'])->update(['camera_role'=>'gate-2']);
        $this->send($data)->assertUnprocessable();$this->assertDatabaseCount('delivery_receipts',0);$this->assertDatabaseCount('vehicle_crossings',0);
    }
    public function test_accepted_operation_can_be_verified_after_gate_archive_without_reapplying_it(): void
    {
        $data=$this->envelope('crossing',$this->payload());$this->send($data)->assertOk();
        \App\Models\Gate::where('code','gate-1')->update(['archived_at'=>now()]);
        $this->send($data)->assertOk();$this->assertDatabaseCount('vehicle_crossings',1);
    }
    public function test_pending_archived_gate_is_rejected_without_creating_receipt_or_movement(): void
    {
        $data=$this->envelope('crossing',$this->payload());
        \App\Models\Gate::where('code','gate-1')->update(['archived_at'=>now()]);
        $this->send($data)->assertUnprocessable();
        $this->assertDatabaseCount('delivery_receipts',0);$this->assertDatabaseCount('vehicle_crossings',0);
    }
    public function test_scalar_envelope_and_non_string_evidence_are_validation_failures(): void
    {
        $data=$this->envelope('crossing',$this->payload());
        $bad=$data;$bad['envelope']='null';$bad['sha256']=hash('sha256','null');
        $this->send($bad)->assertUnprocessable();
        $data=$this->envelope('crossing',$this->payload(),['snapshot'=>'fixture']);$data['media']['snapshot']=[];
        $this->send($data)->assertUnprocessable();$this->assertDatabaseCount('delivery_receipts',0);
    }
    public function test_wrong_generation_bad_credentials_and_tampering_create_no_receipt(): void
    {
        $data=$this->envelope('crossing',$this->payload());
        $this->postJson('/api/v1/integration/deliveries',$data)->assertUnauthorized();
        $bad=$data;$bad['sha256']=str_repeat('0',64);$this->send($bad)->assertUnprocessable();
        DB::table('delivery_installations')->update(['generation'=>(string) Str::uuid()]);
        $this->send($data)->assertServiceUnavailable();$this->assertDatabaseCount('delivery_receipts',0);
    }
    public function test_drain_allows_only_authenticated_durable_delivery_and_fenced_phase_blocks_it(): void
    {
        $data=$this->envelope('crossing',$this->payload());
        File::put($this->directory.'/maintenance.json',json_encode(['schema'=>1,'phase'=>'quiescing','operation'=>(string) Str::uuid()]));
        $this->postJson('/api/v1/integration/deliveries',$data)->assertUnauthorized();
        $this->get('/registry')->assertServiceUnavailable();
        $this->withHeader('X-Api-Key','test-detector-key')->postJson('/api/v1/integration/crossings',$this->payload())->assertServiceUnavailable();
        $this->send($data)->assertOk();
        File::put($this->directory.'/maintenance.json',json_encode(['schema'=>1,'phase'=>'fenced','operation'=>(string) Str::uuid()]));
        $this->send($data)->assertServiceUnavailable()->assertHeader('Retry-After','30');
        $this->assertDatabaseCount('delivery_receipts',1);
    }
    public function test_uncertified_legacy_backup_and_reset_are_guarded_even_when_running(): void
    {
        $this->expectException(\RuntimeException::class);app(\App\Services\BackupService::class)->create();
    }
    public function test_receipt_insert_failure_rolls_back_effects_and_cleans_only_new_staged_media(): void
    {
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lp8AAAAASUVORK5CYII=');
        $data=$this->envelope('crossing',$this->payload(),['snapshot'=>$png]);
        DB::unprepared("CREATE TRIGGER fixture_receipt_failure BEFORE INSERT ON delivery_receipts BEGIN SELECT RAISE(ABORT,'isolated receipt failure'); END");
        $this->send($data)->assertStatus(500);
        $this->assertDatabaseCount('vehicle_crossings',0);$this->assertDatabaseCount('delivery_receipts',0);
        $this->assertSame([],Storage::disk('occurrences')->allFiles());
        DB::unprepared('DROP TRIGGER fixture_receipt_failure');
        $this->send($data)->assertOk();$this->assertDatabaseCount('vehicle_crossings',1);
        $this->assertCount(1,Storage::disk('occurrences')->allFiles());
    }
    public function test_scheduler_does_not_acquire_mutexes_or_restart_writers_while_fenced(): void
    {
        File::put($this->directory.'/maintenance.json',json_encode(['schema'=>1,'phase'=>'fenced','operation'=>(string) Str::uuid()]));
        $this->mock(\App\Services\DeviceServiceRuntime::class)->shouldNotReceive('ensureRunning');
        $kernel=app(\Illuminate\Contracts\Console\Kernel::class);$kernel->rerouteSymfonyCommandEvents();$kernel->setArtisan(null);
        $this->expectException(\RuntimeException::class);
        \Illuminate\Support\Facades\Artisan::call('schedule:run');
    }
}
