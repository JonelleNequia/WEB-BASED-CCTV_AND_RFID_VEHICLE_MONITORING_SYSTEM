<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign In | PHILCST Vehicle Monitoring</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="auth-page">
    <main class="auth-shell auth-shell-modern">
        <section class="auth-hero-panel" aria-labelledby="auth-system-title">
            <div class="auth-badge">PhilCST Academic Access</div>

            <div class="auth-brand-lockup">
                <img
                    src="{{ asset('images/logo-placeholder.png') }}"
                    alt="PHILCST logo placeholder"
                    class="auth-logo"
                >

                <div class="auth-brand-copy">
                    <p class="auth-kicker">Green Metrics Prototype</p>
                    <h1 id="auth-system-title">Web-Based CCTV Vehicle Monitoring System</h1>
                    <p class="auth-subtitle">PhilCST Campus Gate Monitoring</p>
                </div>
            </div>

            <p class="auth-description">
                Access the campus monitoring dashboard for vehicle logs, live monitoring,
                RFID scanning, camera monitoring, and rule-based session matching in one structured academic interface.
            </p>

            <div class="auth-feature-grid" aria-label="System highlights">
                <article class="auth-feature-card auth-feature-card-purple">
                    <span class="auth-feature-label">Monitoring</span>
                    <strong>Every Gate, In and Out</strong>
                    <p>Review campus vehicle flow with browser-ready live monitoring and clean event records.</p>
                </article>

                <article class="auth-feature-card auth-feature-card-green">
                    <span class="auth-feature-label">Camera Monitoring</span>
                    <strong>Prepared for Two Cameras</strong>
                    <p>Keep every gate's camera monitoring organized for guard-side observation.</p>
                </article>

                <article class="auth-feature-card auth-feature-card-orange">
                    <span class="auth-feature-label">Operations</span>
                    <strong>Individual Admin and Guard Accounts</strong>
                    <p>Manage auto-detected events, manual detail completion, and weighted ENTRY to EXIT matching.</p>
                </article>
            </div>
        </section>

        <section class="auth-card auth-login-card" aria-labelledby="login-heading">
            <div class="auth-card-top">
                <p class="auth-card-eyebrow">Account Sign In</p>
                <h2 id="login-heading" class="auth-login-title">Welcome Back</h2>
                <p class="auth-login-copy">
                    Sign in with your activated PHILCST admin or guard account to continue.
                </p>
            </div>

            @include('layouts.partials.flash')

            <form method="POST" action="{{ route('login.store') }}" class="stack-form auth-form">
                @csrf

                <div class="field">
                    <label for="email">Email Address</label>
                    <input
                        id="email"
                        class="auth-input"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        autocomplete="email"
                        inputmode="email"
                        required
                        autofocus
                    >
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input
                        id="password"
                        class="auth-input"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button type="submit" class="button button-primary button-full auth-submit-button">
                    Sign In
                </button>
            </form>
            <p><a href="{{ route('account.activate.form') }}">Activate an account with a privately delivered code</a></p>
        </section>
    </main>
</body>
</html>
