<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account access | PHILCST</title><link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="auth-page"><main class="auth-shell"><section class="auth-card">
    <h1>{{ $mode === 'setup' ? 'First administrator setup' : ($mode === 'activate' ? 'Activate your account' : 'Change your password') }}</h1>
    @if ($mode === 'password' && auth()->user()->password_policy_version < 1)
        <p>Before continuing, replace your existing password with a personal passphrase under the updated policy.</p>
    @endif
    <p>Use at least 15 characters. Spaces and paste are supported. Maximum {{ \App\Rules\PersonalPassword::maxBytes() }} bytes. Avoid common passwords and PHILCST-related phrases.</p>
    @include('layouts.partials.flash')
    <form method="POST" action="{{ route($mode === 'password' ? 'account.password.update' : 'account.'.$mode) }}" class="stack-form" autocomplete="on">
        @csrf
        @if ($mode === 'password') @method('PUT') @endif
        @if ($mode === 'activate')
            <div class="field"><label for="email">Your assigned email</label><input id="email" name="email" type="email" required autocomplete="username" value="{{ old('email') }}"></div>
        @endif
        @if ($mode !== 'password')
            <p>Enter the code delivered privately by {{ $mode === 'setup' ? 'your local installer' : 'your administrator' }}. It expires after 24 hours and allows five attempts.</p>
            <div class="field"><label for="code">{{ $mode === 'setup' ? 'Setup' : 'Activation' }} code</label><input id="code" name="code" type="password" required maxlength="64" autocomplete="off"></div>
        @else
            <div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
        @endif
        <div class="field"><label for="password">New personal password</label><input id="password" name="password" type="password" required minlength="15" autocomplete="new-password"></div>
        <div class="field"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" required minlength="15" autocomplete="new-password"></div>
        <button class="button button-primary" type="submit">{{ $mode === 'password' ? 'Change password' : 'Complete account setup' }}</button>
    </form>
    @if ($mode === 'password')
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="button button-secondary">Logout</button></form>
    @else
        <a href="{{ route('login') }}">Back to sign in</a>
    @endif
</section></main></body></html>
