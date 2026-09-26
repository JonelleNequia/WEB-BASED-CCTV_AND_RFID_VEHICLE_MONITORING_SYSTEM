<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'PHILCST Vehicle Monitoring')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    {{-- UI Phase 5: restore the collapsed sidebar before first paint (per browser). --}}
    <script>
        try {
            if (localStorage.getItem('ui.sidebar') === 'collapsed') {
                document.documentElement.classList.add('sidebar-collapsed');
            }
        } catch (error) {}
    </script>
    @stack('styles')
</head>
<body class="app-body">
    <a href="#main-content" class="skip-link">Skip to content</a>
    <div class="nav-progress" aria-hidden="true"></div>

    <div class="app-shell">
        <aside class="sidebar" id="app-sidebar">
            {{-- UI Phase 1: compact brand; the big topbar is replaced by <x-page-header> on each page. --}}
            <div class="brand-block">
                <span class="brand-mark">PHILCST</span>
                <span class="brand-name">Vehicle Monitoring</span>
                {{-- UI Phase 5: collapsible sidebar. --}}
                <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 1 0 1.4L10.4 12l4.3 4.3a1 1 0 0 1-1.4 1.4l-5-5a1 1 0 0 1 0-1.4l5-5a1 1 0 0 1 1.4 0"/></svg>
                </button>
            </div>

            @include('layouts.partials.navigation')
        </aside>

        <div class="content-shell">
            <main class="page-content" id="main-content" tabindex="-1">
                @include('layouts.partials.flash')
                @yield('content')
            </main>
        </div>
    </div>

    <script src="{{ asset('js/ui.js') }}"></script>
    @stack('scripts')
</body>
</html>
