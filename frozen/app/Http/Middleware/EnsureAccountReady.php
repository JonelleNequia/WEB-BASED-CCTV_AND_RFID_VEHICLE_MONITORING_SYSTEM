<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountReady
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();
        if ($request->user() && $request->hasSession()) {
            if (! $user || $user->account_status !== 'active' || $request->session()->get('auth_version') !== $user->session_version) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson() ? response()->json(['message' => 'Sign in again.'], 401) : redirect()->route('login');
            }
            if ($user->password_policy_version < 1 && ! $request->routeIs('account.password.*', 'logout')) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Set a new personal password before continuing.', 'password_change_required' => true], 403)
                    : redirect()->route('account.password.edit');
            }
            Auth::setUser($user);
        }

        return $next($request);
    }
}
