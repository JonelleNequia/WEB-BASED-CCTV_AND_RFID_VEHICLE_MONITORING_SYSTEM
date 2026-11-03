<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountSecurityService
{
    public const MAX_ATTEMPTS = 5;

    public const CODE_HOURS = 24;

    protected function validatePassword(#[\SensitiveParameter] string $password): void
    {
        \Illuminate\Support\Facades\Validator::make(['password' => $password], [
            'password' => ['required', 'string', new \App\Rules\PersonalPassword],
        ])->validate();
    }

    /** Write before reads: serialized on SQLite and row-locked on MySQL. */
    public function locked(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            DB::table('account_security_state')->where('id', 1)->increment('version');

            return $callback();
        }, 5);
    }

    public function record(string $action, ?User $actor = null, ?User $subject = null): void
    {
        DB::table('security_events')->insert(['action' => $action, 'actor_id' => $actor?->id, 'subject_id' => $subject?->id, 'created_at' => now()]);
    }

    public function issueSetup(string $email, string $name, bool $initialize = false, bool $replace = false): ?string
    {
        return $this->locked(function () use ($email, $name, $initialize, $replace) {
            $state = DB::table('account_security_state')->where('id', 1)->first();
            if ($state->setup_completed_at || User::query()->exists()) {
                // Existing installations are closed even if their admin is later removed.
                if (! $state->setup_completed_at) {
                    DB::table('account_security_state')->where('id', 1)->update(['setup_completed_at' => now(), 'setup_hash' => null]);
                }

                return null;
            }
            if ((! $state->setup_hash && ! $initialize) || ($state->setup_hash && ! $replace)) {
                return null;
            }
            $code = bin2hex(random_bytes(32));
            DB::table('account_security_state')->where('id', 1)->update([
                'setup_hash' => hash('sha256', $code), 'setup_email' => $email, 'setup_name' => $name,
                'setup_expires_at' => now()->addHours(self::CODE_HOURS), 'setup_attempts' => 0,
            ]);
            $this->record($state->setup_hash ? 'setup_code_replaced' : 'setup_code_issued');

            return $code;
        });
    }

    public function completeSetup(#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $password): ?User
    {
        $this->validatePassword($password);

        return $this->locked(function () use ($code, $password) {
            $state = DB::table('account_security_state')->where('id', 1)->first();
            if ($state->setup_completed_at || ! $state->setup_hash || ! $state->setup_expires_at || $state->setup_attempts >= self::MAX_ATTEMPTS
                || now()->gte($state->setup_expires_at)) {
                return null;
            }
            DB::table('account_security_state')->where('id', 1)->increment('setup_attempts');
            if (! hash_equals($state->setup_hash, hash('sha256', $code))) {
                $this->record('setup_attempt_rejected');

                return null;
            }
            if (User::query()->exists()) {
                DB::table('account_security_state')->where('id', 1)->update(['setup_completed_at' => now(), 'setup_hash' => null]);

                return null;
            }
            $user = User::query()->create(['name' => $state->setup_name, 'email' => $state->setup_email, 'role' => 'admin', 'password' => Hash::make($password)]);
            $user->forceFill(['account_status' => 'active', 'password_policy_version' => 1, 'password_changed_at' => now()])->save();
            DB::table('account_security_state')->where('id', 1)->update(['setup_completed_at' => now(), 'setup_hash' => null, 'setup_expires_at' => null]);
            $this->record('first_admin_created', null, $user);

            return $user;
        });
    }

    protected function authorizeAdmin(User $actor): void
    {
        $actor->refresh();
        abort_unless($actor->isAdmin() && $actor->account_status === 'active' && $actor->password_policy_version >= 1, 403);
    }

    public function createAccount(User $actor, array $data): array
    {
        return $this->locked(function () use ($actor, $data) {
            $this->authorizeAdmin($actor);
            $user = User::query()->create(['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'], 'password' => bin2hex(random_bytes(32))]);
            $user->forceFill(['account_status' => 'pending'])->save();
            $this->record('account_created', $actor, $user);

            return [$user, $this->issueActivation($user)];
        });
    }

    protected function issueActivation(User $user): string
    {
        $code = bin2hex(random_bytes(32));
        $user->forceFill(['activation_hash' => hash('sha256', $code), 'activation_expires_at' => now()->addHours(self::CODE_HOURS), 'activation_attempts' => 0])->save();

        return $code;
    }

    public function replaceActivation(User $actor, User $user): string
    {
        return $this->locked(function () use ($actor, $user) {
            $this->authorizeAdmin($actor);
            $user->refresh();
            abort_unless($user->account_status === 'pending', 422, 'Only pending accounts can receive a replacement code.');
            $code = $this->issueActivation($user);
            $this->record('activation_code_replaced', $actor, $user);

            return $code;
        });
    }

    public function activate(string $email, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $password): bool
    {
        $this->validatePassword($password);

        return $this->locked(function () use ($email, $code, $password) {
            $user = User::query()->where('email', $email)->first();
            if (! $user || $user->account_status !== 'pending' || ! $user->activation_hash || ! $user->activation_expires_at || $user->activation_attempts >= self::MAX_ATTEMPTS
                || now()->gte($user->activation_expires_at)) {
                return false;
            }
            $user->forceFill(['activation_attempts' => $user->activation_attempts + 1])->save();
            if (! hash_equals($user->activation_hash, hash('sha256', $code))) {
                $this->record('activation_attempt_rejected', null, $user);

                return false;
            }
            $user->forceFill(['password' => Hash::make($password), 'account_status' => 'active', 'password_policy_version' => 1,
                'password_changed_at' => now(), 'activation_hash' => null, 'activation_expires_at' => null,
                'session_version' => $user->session_version + 1, 'remember_token' => Str::random(60)])->save();
            $this->record('account_activated', null, $user);

            return true;
        });
    }

    public function changePassword(User $user, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $password): void
    {
        $this->validatePassword($password);
        $this->locked(function () use ($user, $current, $password) {
            $user->refresh();
            abort_unless($user->account_status === 'active', 403);
            if (! Hash::check($current, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            if (Hash::check($password, $user->password)) {
                throw ValidationException::withMessages(['password' => 'Choose a new personal password.']);
            }
            $user->forceFill(['password' => Hash::make($password), 'password_policy_version' => 1, 'password_changed_at' => now(),
                'session_version' => $user->session_version + 1, 'remember_token' => Str::random(60)])->save();
            $this->record('password_changed', $user, $user);
        });
    }

    public function updateAccount(User $actor, User $user, string $role, string $status): void
    {
        $this->locked(function () use ($actor, $user, $role, $status) {
            $this->authorizeAdmin($actor);
            $user->refresh();
            abort_unless(in_array($role, ['admin', 'guard'], true) && in_array($status, ['active', 'disabled'], true), 422);
            abort_if($user->account_status === 'pending', 422, 'Activate the pending account first.');
            if ($user->isAdmin() && $user->account_status === 'active' && ($role !== 'admin' || $status !== 'active')
                && User::query()->where('role', 'admin')->where('account_status', 'active')->count() <= 1) {
                throw ValidationException::withMessages(['account' => 'At least one active administrator is required.']);
            }
            $user->forceFill(['role' => $role, 'account_status' => $status, 'session_version' => $user->session_version + 1,
                'remember_token' => Str::random(60)])->save();
            $this->record('account_permissions_changed', $actor, $user);
        });
    }
}
