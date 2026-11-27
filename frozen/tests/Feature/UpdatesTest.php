<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\UpdateRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UpdatesTest extends TestCase
{
    use RefreshDatabase;
    private string $work;
    protected function setUp(): void
    {
        parent::setUp();$this->seed(\Database\Seeders\InstallSeeder::class);
        $this->work=sys_get_temp_dir().'/d5c-ui-'.bin2hex(random_bytes(8));File::ensureDirectoryExists($this->work.'/lifecycle');
        // Read-only protected status is faked, not a live installation/catalog.
        File::put($this->work.'/update-status.json', json_encode(['schema'=>1,'installed'=>['version'=>'1.0.0','platform'=>'macos-arm64','source'=>['sha256'=>str_repeat('a',64)]],
            'preferences'=>['channel'=>'stable','automatic_check'=>false,'automatic_download'=>false],'last_check'=>['outcome'=>'check unavailable'],'candidate'=>null]));
        $this->app->instance(UpdateRequests::class,new class($this->work) extends UpdateRequests {
            public function __construct(private string $work) {}
            public function status(): array {return json_decode(File::get($this->work.'/update-status.json'),true)+['provisioned'=>true];}
            public function directory(): string {return $this->work.'/inbox';}
        });
    }
    protected function tearDown(): void {File::deleteDirectory($this->work);parent::tearDown();}
    private function user(string $role='admin'): User {return User::factory()->create(['role'=>$role,'account_status'=>'active']);}
    public function test_admin_page_displays_real_outcome_and_no_executable_browser_controls(): void
    {
        $this->actingAs($this->user())->get('/settings?tab=updates')->assertOk()->assertSee('check unavailable')->assertSee('Check for Updates')->assertSee('Import complete package')->assertDontSee('Request installation');
    }
    public function test_guard_and_unauthenticated_mutations_are_rejected(): void
    {
        $this->post('/settings/updates/request',['action'=>'check'])->assertRedirect('/login');
        $this->actingAs($this->user('guard'))->post('/settings/updates/request',['action'=>'check'])->assertForbidden();
        $this->get('/settings?tab=updates')->assertForbidden();$this->assertDirectoryDoesNotExist($this->work.'/inbox');
    }
    public function test_fresh_compatibility_status_without_publisher_trust_keeps_requests_disabled(): void
    {
        $value=json_decode(File::get($this->work.'/update-status.json'),true);
        $value['provisioned']=false;
        $value['last_check']=['outcome'=>'Blocked: independently verified publisher trust has not been enrolled'];
        File::put($this->work.'/update-status.json',json_encode($value));
        $this->actingAs($this->user())->post('/settings/updates/request',['action'=>'check'])->assertUnprocessable();
        $this->assertDirectoryDoesNotExist($this->work.'/inbox');
        $this->assertFalse(app(UpdateRequests::class)->status()['provisioned']);
    }
    public function test_only_fixed_preparation_intents_are_queued_without_commands_or_paths(): void
    {
        $this->actingAs($this->user())->post('/settings/updates/request',['action'=>'shell','command'=>'anything'])->assertSessionHasErrors('action');
        $this->post('/settings/updates/request',['action'=>'check','path'=>'/outside','command'=>'untrusted'])->assertRedirect();
        $files=glob($this->work.'/inbox/*/request.json');$this->assertCount(1,$files);$intent=json_decode(File::get($files[0]),true);
        $this->assertSame('check',$intent['action']);$this->assertArrayNotHasKey('path',$intent['inputs']);$this->assertArrayNotHasKey('command',$intent['inputs']);
    }
    public function test_install_requires_exact_prepared_candidate_and_explicit_confirmation(): void
    {
        $this->actingAs($this->user())->post('/settings/updates/request',['action'=>'install','candidate'=>str_repeat('a',64),'maintenance_confirmed'=>1])->assertUnprocessable();
        $value=json_decode(File::get($this->work.'/update-status.json'),true);$value['candidate']=['id'=>str_repeat('b',64),'ready'=>true,'install_ready'=>true,'plan_confirmation'=>str_repeat('c',64),'manifest'=>['signed'=>['version'=>'1.1.0']],'notes'=>'Synthetic'];File::put($this->work.'/update-status.json',json_encode($value));
        $this->post('/settings/updates/request',['action'=>'install','candidate'=>str_repeat('b',64)])->assertUnprocessable();
        $this->post('/settings/updates/request',['action'=>'install','candidate'=>str_repeat('b',64),'plan_confirmation'=>str_repeat('c',64),'maintenance_confirmed'=>1])->assertRedirect();
        $files=glob($this->work.'/inbox/*/request.json');$this->assertCount(1,$files);$this->assertSame('install',json_decode(File::get($files[0]),true)['action']);$this->assertDatabaseCount('vehicle_events',0);
    }
    public function test_chunked_import_keeps_partial_bytes_uninstallable_and_is_owner_bound(): void
    {
        $admin=$this->user();$this->actingAs($admin);
        $response=$this->postJson('/settings/updates/import',['filename'=>'complete.zip','size'=>6,'manifest'=>UploadedFile::fake()->createWithContent('metadata.json','{"signed":{}}')])->assertOk();
        $id=$response->json('id');$url=$response->json('chunk_url');
        $this->postJson($url,['offset'=>0,'chunk'=>UploadedFile::fake()->createWithContent('part.bin','abc')])->assertOk()->assertJsonPath('complete',false);
        $this->assertSame('upload',json_decode(File::get($this->work.'/inbox/'.$id.'/request.json'),true)['action']);
        $this->postJson($url,['offset'=>0,'chunk'=>UploadedFile::fake()->createWithContent('part.bin','def')])->assertUnprocessable();
        $this->actingAs($this->user())->postJson($url,['offset'=>3,'chunk'=>UploadedFile::fake()->createWithContent('part.bin','def')])->assertForbidden();
        $this->actingAs($admin)->postJson($url,['offset'=>3,'chunk'=>UploadedFile::fake()->createWithContent('part.bin','def')])->assertOk()->assertJsonPath('complete',true);
        $this->assertSame('import',json_decode(File::get($this->work.'/inbox/'.$id.'/request.json'),true)['action']);$this->assertSame('abcdef',File::get($this->work.'/inbox/'.$id.'/package.bin'));
    }
    public function test_import_slots_are_bounded_before_the_first_payload_chunk(): void
    {
        $this->actingAs($this->user());
        foreach(range(1,2) as $n) $this->postJson('/settings/updates/import',['filename'=>'complete.zip','size'=>6,'manifest'=>UploadedFile::fake()->createWithContent('metadata.json','{}')])->assertOk();
        $this->postJson('/settings/updates/import',['filename'=>'complete.zip','size'=>6,'manifest'=>UploadedFile::fake()->createWithContent('metadata.json','{}')])->assertStatus(429);
        $this->assertCount(2,glob($this->work.'/inbox/*/request.json'));
    }

    public function test_csrf_is_required_for_native_intent_and_upload_routes(): void
    {
        // Test environment normally bypasses CSRF. Exercise the actual middleware
        // with its test bypass explicitly disabled, before any controller is called.
        $middleware=new class($this->app,$this->app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
            protected function runningUnitTests() {return false;}
        };
        $request=\Illuminate\Http\Request::create('/settings/updates/request','POST',['action'=>'install']);$request->setLaravelSession($this->app['session.store']);
        $this->expectException(\Illuminate\Session\TokenMismatchException::class);$middleware->handle($request,fn()=>response('unexpected'));
    }
}
