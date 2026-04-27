<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'PHILCST Parking Monitoring')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @stack('styles')
</head>
<body class="app-body">
    <div class="app-shell">
        <aside class="sidebar">
            <div class="brand-block">
                <div class="brand-mark-wrap">
                    <span class="brand-mark">PHILCST</span>
                    <span class="brand-chip">Parking Operations</span>
                </div>

                <h1>Campus Parking Monitoring</h1>
            </div>

            @include('layouts.partials.navigation')
        </aside>

        <div class="content-shell">
            <header class="topbar">
                <div class="topbar-main">
                    <p class="topbar-breadcrumb">Parking Operations / @yield('page-title', 'Dashboard')</p>
                    <p class="eyebrow">@yield('eyebrow', 'Offline Local System')</p>
                    <h2>@yield('page-title', 'Dashboard')</h2>
                    <p class="page-copy">@yield('page-description', 'Manage parking access, RFID activity, guest monitoring, and camera support from one console.')</p>
                </div>

                <div class="topbar-meta">
                    <div class="topbar-user">
                        <span class="topbar-avatar">
                            <img src="{{ asset('images/logo-placeholder.png') }}" alt="PHILCST logo">
                        </span>
                        <div>
                            <strong>{{ auth()->user()->name ?? 'System Administrator' }}</strong>
                            <span>{{ auth()->user()->email }}</span>
                        </div>
                    </div>

                    <div class="topbar-meta-row">
                        <span class="chip chip-brand">Offline Local</span>
                        <span class="topbar-time">{{ now()->format('M d, Y h:i A') }}</span>
                    </div>
                </div>
            </header>

            <main class="page-content">
                @include('layouts.partials.flash')
                @yield('content')
            </main>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
