<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'PHILCST Station')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @stack('styles')
</head>
<body class="portal-body">
    <div class="portal-shell">
        <header class="portal-topbar">
            <div class="portal-topbar-main">
                <p class="eyebrow">@yield('portal-kicker', 'PHILCST Station View')</p>
                <h1>@yield('page-title', 'Portal')</h1>
                <p class="page-copy">@yield('page-description', 'RFID station view with optional live camera support.')</p>
            </div>

            <div class="portal-topbar-meta">
                <span class="chip chip-brand">Station Screen</span>
                <span class="topbar-time" data-live-clock>{{ now()->format('M d, Y h:i:s A') }}</span>
            </div>
        </header>

        <main class="portal-content">
            @include('layouts.partials.flash')
            @yield('content')
        </main>
    </div>

    @stack('scripts')
</body>
</html>
