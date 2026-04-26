<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin Login | PHILCST Vehicle Monitoring</title>
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
                    <p class="auth-subtitle">PhilCST Campus Entrance and Exit Monitoring</p>
                </div>
            </div>

            <p class="auth-description">
                Access the campus monitoring dashboard for vehicle logs, live monitoring,
                RFID scanning, camera monitoring, and rule-based session matching in one structured academic interface.
            </p>

            <div class="auth-feature-grid" aria-label="System highlights">
                <article class="auth-feature-card auth-feature-card-purple">
                    <span class="auth-feature-label">Monitoring</span>
                    <strong>Entrance and Exit Visibility</strong>
                    <p>Review campus vehicle flow with browser-ready live monitoring and clean event records.</p>
                </article>

                <article class="auth-feature-card auth-feature-card-green">
                    <span class="auth-feature-label">Camera Monitoring</span>
                    <strong>Prepared for Two Cameras</strong>
                    <p>Keep Entrance and Exit camera monitoring organized for guard-side observation.</p>
                </article>

                <article class="auth-feature-card auth-feature-card-orange">
                    <span class="auth-feature-label">Operations</span>
                    <strong>Single Admin Workflow</strong>
                    <p>Manage auto-detected events, manual detail completion, and weighted ENTRY to EXIT matching.</p>
                </article>
            </div>
        </section>

        <section class="auth-card auth-login-card" aria-labelledby="login-heading">
            <div class="auth-card-top">
                <p class="auth-card-eyebrow">Admin Sign In</p>
                <h2 id="login-heading" class="auth-login-title">Welcome Back</h2>
                <p class="auth-login-copy">
                    Sign in with the authorized PHILCST admin account to continue to the monitoring system.
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
                        value="{{ old('email', 'admin@philcst.local') }}"
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
                    Login to Dashboard
                </button>
            </form>
        </section>
    </main>
</body>
</html>
