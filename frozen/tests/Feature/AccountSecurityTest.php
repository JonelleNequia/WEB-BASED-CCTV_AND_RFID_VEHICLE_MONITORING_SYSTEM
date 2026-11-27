<?php

namespace Tests\Feature;

use App\Models\User;
use App\Rules\PersonalPassword;
use App\Services\AccountSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = '  quiet rivers carry silver boats  ';

    public function test_fresh_setup_requires_local_issuance_and_consumes_the_code_once_without_operational_access(): void
    {
        $service = app(AccountSecurityService::class);
        $this->get('/setup')->assertNotFound();
        $this->postJson('/setup', $this->credentials('not-issued'))->assertUnprocessable();
        $this->assertNull($service->issueSetup('owner@school.test', 'Owner'));
        $code = $service->issueSetup('owner@school.test', 'Owner', initialize: true);
        $this->assertSame(hash('sha256', $code), DB::table('account_security_state')->value('setup_hash'));
        $this->get('/setup')->assertOk()->assertDontSee($code);
        $this->post('/setup', $this->credentials($code) + ['role' => 'guard', 'email' => 'attacker@school.test'])
            ->assertRedirect(route('login'));
        $user = User::query()->sole();
        $this->assertSame(['owner@school.test', 'admin', 'active'], [$user->email, $user->role, $user->account_status]);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertFalse(Hash::check(trim(self::PASSWORD), $user->password));
        $this->assertGuest();
        $this->get('/admin')->assertRedirect(route('login'));
        $this->postJson('/setup', $this->credentials($code))->assertUnprocessable();
        $this->get('/setup')->assertNotFound();
        $this->assertNull(DB::table('account_security_state')->value('setup_hash'));
        User::query()->delete();
        $this->assertNull($service->issueSetup('other@school.test', 'Other', initialize: true, replace: true));
        $this->get('/setup')->assertNotFound();
    }

    public function test_setup_expiry_attempt_limits_and_replacement(): void
    {
        $service = app(AccountSecurityService::class);
        $code = $service->issueSetup('owner@school.test', 'Owner', initialize: true);
        $this->travel(25)->hours();
        $this->assertNull($service->completeSetup($code, self::PASSWORD));
        $this->travelBack();
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($service->completeSetup(str_repeat('f', 64), self::PASSWORD));
        }
        $this->assertSame(5, DB::table('account_security_state')->value('setup_attempts'));
        $this->assertNull($service->completeSetup($code, self::PASSWORD));
        $new = $service->issueSetup('owner@school.test', 'Owner', replace: true);
        $this->assertNull($service->completeSetup($code, self::PASSWORD));
        $this->assertNotNull($service->completeSetup($new, self::PASSWORD));
    }

    public function test_admin_creates_pending_account_delivers_once_and_activation_cannot_change_identity_or_role(): void
    {
        $admin = User::factory()->create();
        $response = $this->actingAs($admin)->post('/accounts', ['name' => 'Individual Guard', 'email' => 'guard@school.test', 'role' => 'guard']);
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $code = $response->viewData('code');
        $user = User::query()->where('email', 'guard@school.test')->sole();
        $this->assertSame('pending', $user->account_status);
        $this->assertSame(hash('sha256', $code), $user->activation_hash);
        $this->get('/accounts')->assertOk()->assertDontSee($code);
        $this->actingAs($user)->getJson('/api/recent-station-logs')->assertUnauthorized();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        Auth::forgetGuards();
        $this->post('/activate', $this->credentials($code) + ['email' => $user->email, 'role' => 'admin', 'name' => 'Attacker'])
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $user->refresh();
        $this->assertSame(['Individual Guard', 'guard', 'active', null], [$user->name, $user->role, $user->account_status, $user->activation_hash]);
        $this->postJson('/activate', $this->credentials($code) + ['email' => $user->email])->assertUnprocessable();
        $this->assertDatabaseHas('security_events', ['action' => 'account_created', 'actor_id' => $admin->id, 'subject_id' => $user->id]);
        $this->assertDatabaseHas('security_events', ['action' => 'account_activated', 'subject_id' => $user->id]);
        $this->assertStringNotContainsString($code, DB::table('security_events')->get()->toJson());
    }

    public function test_activation_expiry_attempts_replacement_and_replay(): void
    {
        $admin = User::factory()->create();
        $service = app(AccountSecurityService::class);
        [$user, $code] = $service->createAccount($admin, ['name' => 'Guard', 'email' => 'guard@school.test', 'role' => 'guard']);
        $this->travel(25)->hours();
        $this->assertFalse($service->activate($user->email, $code, self::PASSWORD));
        $this->travelBack();
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($service->activate($user->email, 'bad', self::PASSWORD));
        }
        $this->assertSame(5, $user->fresh()->activation_attempts);
        $this->assertFalse($service->activate($user->email, $code, self::PASSWORD));
        $new = $service->replaceActivation($admin, $user);
        $this->assertFalse($service->activate($user->email, $code, self::PASSWORD));
        $this->assertTrue($service->activate($user->email, $new, self::PASSWORD));
        $this->assertFalse($service->activate($user->email, $new, self::PASSWORD));
    }

    public function test_guard_guest_pending_and_legacy_sessions_cannot_manage_accounts(): void
    {
        $user = User::factory()->create();
        $routes = [['GET', '/accounts', []], ['POST', '/accounts', []], ['POST', '/accounts/'.$user->id.'/activation', []], ['PATCH', '/accounts/'.$user->id, []]];
        foreach ($routes as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertUnauthorized();
        }
        $guard = User::factory()->create(['role' => 'guard']);
        foreach ($routes as [$method, $url, $body]) {
            $this->actingAs($guard)->json($method, $url, $body)->assertForbidden();
        }
        foreach (['pending', 'disabled'] as $state) {
            $user->forceFill(['account_status' => $state])->save();
            $this->actingAs($user)->getJson('/admin/live-state')->assertUnauthorized();
            $this->actingAs($user)->getJson('/api/recent-event-logs')->assertUnauthorized();
        }
        $user->forceFill(['account_status' => 'active', 'password_policy_version' => 0])->save();
        $this->actingAs($user)->get('/gates')->assertRedirect(route('account.password.edit'));
        $this->getJson('/api/recent-event-logs')->assertForbidden();
        $this->postJson('/accounts', [])->assertForbidden();
        $this->get('/account/password')->assertOk();
        $this->post('/logout')->assertRedirect(route('login'));
    }

    public function test_password_policy_spaces_long_inputs_blocklist_and_bcrypt_fallback_are_explicit(): void
    {
        $valid = [self::PASSWORD, 'these seven quiet rivers carry our small boats', str_repeat('long unique phrase ', 4)];
        foreach ($valid as $password) {
            $this->assertTrue(Validator::make(compact('password'), ['password' => new PersonalPassword])->passes());
        }
        foreach (['too short', 'PasswordPassword!', 'PHILCST quiet rivers', str_repeat('x', 80), str_repeat('long ', 300), "fifteen or more\0chars"] as $password) {
            $this->assertTrue(Validator::make(compact('password'), ['password' => new PersonalPassword])->fails());
        }
        config(['hashing.driver' => 'bcrypt']);
        $password = str_repeat('four different words ', 4);
        $this->assertTrue(Validator::make(compact('password'), ['password' => new PersonalPassword])->fails());
        $this->assertSame(72, PersonalPassword::maxBytes());
        $this->assertTrue(Validator::make(['password' => str_repeat('abcd', 16)], ['password' => new PersonalPassword])->passes());
    }

    public function test_legacy_bcrypt_authentication_rehashes_but_requires_new_password_and_revokes_other_sessions(): void
    {
        $user = User::factory()->create();
        $oldHash = password_hash('old private password', PASSWORD_BCRYPT, ['cost' => 4]);
        DB::table('users')->where('id', $user->id)->update(['password' => $oldHash, 'password_policy_version' => 0]);
        $this->post('/login', ['email' => $user->email, 'password' => 'old private password'])->assertRedirect(route('account.password.edit'));
        $this->assertTrue(Hash::check('old private password', $user->fresh()->password));
        if (config('hashing.driver') === 'argon2id') {
            $this->assertSame('argon2id', password_get_info($user->fresh()->password)['algoName']);
        }
        $oldVersion = $user->fresh()->session_version;
        $this->get('/admin')->assertRedirect(route('account.password.edit'));
        $this->put('/account/password', $this->credentials('unused') + ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->put('/account/password', $this->credentials('unused') + ['current_password' => 'old private password'])->assertRedirect(route('home'));
        $this->assertSame(1, $user->fresh()->password_policy_version);
        $this->assertSame($oldVersion + 1, $user->fresh()->session_version);
        $this->get('/admin')->assertOk();
        $this->withSession(['auth_version' => $oldVersion])->getJson('/api/recent-event-logs')->assertUnauthorized();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->assertDatabaseHas('security_events', ['action' => 'password_changed', 'subject_id' => $user->id]);
    }

    public function test_last_active_admin_cannot_be_disabled_or_demoted_and_others_can(): void
    {
        $admin = User::factory()->create();
        app(AccountSecurityService::class)->createAccount($admin, ['name' => 'Pending Admin', 'email' => 'pending.admin@school.test', 'role' => 'admin']);
        foreach ([['guard', 'active'], ['admin', 'disabled']] as [$role, $status]) {
            $this->actingAs($admin)->patch('/accounts/'.$admin->id, ['role' => $role, 'account_status' => $status])->assertSessionHasErrors('account');
        }
        $second = User::factory()->create();
        $this->actingAs($admin)->patch('/accounts/'.$second->id, ['role' => 'guard', 'account_status' => 'disabled'])->assertRedirect(route('accounts.index'));
        $this->assertSame(['guard', 'disabled'], [$second->fresh()->role, $second->fresh()->account_status]);
        $this->assertDatabaseHas('security_events', ['action' => 'account_permissions_changed', 'subject_id' => $second->id]);
    }

    public function test_existing_argon_hashes_verify_and_are_upgraded_on_login(): void
    {
        if (! in_array('argon2i', password_algos(), true)) {
            $this->markTestSkipped('Argon2i unavailable on this runtime.');
        }
        $user = User::factory()->create();
        $hash = password_hash(self::PASSWORD, PASSWORD_ARGON2I, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
        DB::table('users')->where('id', $user->id)->update(['password' => $hash]);
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect(route('dashboard.index'));
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        if (config('hashing.driver') === 'argon2id') {
            $this->assertSame('argon2id', password_get_info($user->fresh()->password)['algoName']);
        }
    }

    public function test_hash_looking_personal_password_is_still_hashed_as_exact_plaintext(): void
    {
        if (config('hashing.driver') !== 'argon2id') {
            $this->markTestSkipped('This literal-hash test needs the longer Argon2id input policy.');
        }
        $literal = Hash::make('not the selected personal password');
        $service = app(AccountSecurityService::class);
        $code = $service->issueSetup('owner@school.test', 'Owner', initialize: true);
        $user = $service->completeSetup($code, $literal);
        $this->assertNotSame($literal, $user->password);
        $this->assertTrue(Hash::check($literal, $user->password));
        $this->assertFalse(Hash::check('not the selected personal password', $user->password));
    }

    public function test_production_seeders_do_not_create_change_or_delete_accounts(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->assertSame(0, User::query()->count());
        $admin = User::factory()->create(['email' => 'admin@philcst.local']);
        $guard = User::factory()->create(['email' => 'guard@philcst.local', 'role' => 'guard']);
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        $this->seed(\Database\Seeders\AdminUserSeeder::class);
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
    }

    public function test_codes_and_passwords_are_not_flashed_after_validation_failure(): void
    {
        $this->post('/activate', ['email' => 'guard@school.test', 'code' => 'test-secret-code', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $old = session()->getOldInput();
        $this->assertArrayNotHasKey('code', $old);
        $this->assertArrayNotHasKey('password', $old);
        $this->assertArrayNotHasKey('password_confirmation', $old);
    }

    private function credentials(string $code): array
    {
        return ['code' => $code, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }
}
