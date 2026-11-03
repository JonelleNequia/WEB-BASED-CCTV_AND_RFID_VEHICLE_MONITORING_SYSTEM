<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\PersonalPassword;
use App\Services\AccountSecurityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    protected function passwordRules(): array
    {
        return ['required', 'string', 'confirmed', new PersonalPassword];
    }

    public function setupForm()
    {
        abort_unless(\Illuminate\Support\Facades\DB::table('account_security_state')->where('id', 1)
            ->whereNull('setup_completed_at')->whereNotNull('setup_hash')->where('setup_expires_at', '>', now())
            ->where('setup_attempts', '<', AccountSecurityService::MAX_ATTEMPTS)->exists(), 404);

        return response()->view('auth.account-access', ['mode' => 'setup'])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function setup(Request $request, AccountSecurityService $security)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64'], 'password' => $this->passwordRules()]);
        if (! $security->completeSetup($data['code'], $data['password'])) {
            throw ValidationException::withMessages(['code' => 'Setup code invalid, expired, exhausted or already used. Ask the local installer.']);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Administrator setup complete. Sign in with your personal password.');
    }

    public function activationForm()
    {
        return response()->view('auth.account-access', ['mode' => 'activate'])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function activate(Request $request, AccountSecurityService $security)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'string', 'max:64'], 'password' => $this->passwordRules()]);
        if (! $security->activate($data['email'], $data['code'], $data['password'])) {
            throw ValidationException::withMessages(['code' => 'Activation code invalid, expired, exhausted or already used. Ask your administrator.']);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Account activated. Sign in with your personal password.');
    }

    public function passwordForm()
    {
        return response()->view('auth.account-access', ['mode' => 'password'])->header('Cache-Control', 'no-store');
    }

    public function password(Request $request, AccountSecurityService $security)
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => $this->passwordRules()]);
        $security->changePassword($request->user(), $data['current_password'], $data['password']);
        $request->session()->regenerate(true);
        $request->session()->put('auth_version', $request->user()->session_version);

        return redirect()->route('home')->with('status', 'Password changed. Other sessions have been signed out.');
    }

    public function index()
    {
        return response()->view('accounts.index', ['users' => User::query()->orderBy('name')->paginate(20)])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, AccountSecurityService $security)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'role' => ['required', Rule::in(['admin', 'guard'])]]);
        [$user, $code] = $security->createAccount($request->user(), $data);

        return $this->delivery($user, $code);
    }

    public function replace(Request $request, User $user, AccountSecurityService $security)
    {
        return $this->delivery($user, $security->replaceActivation($request->user(), $user));
    }

    protected function delivery(User $user, #[\SensitiveParameter] string $code)
    {
        // Direct response only: never put the code into URLs, flash sessions or logs.
        return response()->view('accounts.delivery', ['account' => $user, 'code' => $code])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function update(Request $request, User $user, AccountSecurityService $security)
    {
        $data = $request->validate(['role' => ['required', 'in:admin,guard'], 'account_status' => ['required', 'in:active,disabled']]);
        $security->updateAccount($request->user(), $user, $data['role'], $data['account_status']);

        return redirect()->route('accounts.index')->with('status', 'Account permissions updated.');
    }
}
