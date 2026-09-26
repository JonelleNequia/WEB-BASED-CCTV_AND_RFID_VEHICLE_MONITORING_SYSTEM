<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'PHILCST Vehicle Monitoring')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @stack('styles')
</head>
<body class="app-body">
    <div class="app-shell">
        <aside class="sidebar">
            {{-- UI Phase 1: compact brand; the big topbar is replaced by <x-page-header> on each page. --}}
            <div class="brand-block">
                <span class="brand-mark">PHILCST</span>
                <span class="brand-name">Vehicle Monitoring</span>
            </div>

            @include('layouts.partials.navigation')
        </aside>

        <div class="content-shell">
            <main class="page-content">
                @include('layouts.partials.flash')
                @yield('content')
            </main>
        </div>
    </div>

    <script src="{{ asset('js/ui.js') }}"></script>
    @stack('scripts')
</body>
</html>
