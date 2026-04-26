@php
    $isAdmin = auth()->user()?->isAdmin() === true;

    $mainItems = [
        [
            'label' => 'Dashboard',
            'route' => route('dashboard.index'),
            'active' => request()->routeIs('dashboard.*'),
            'icon' => 'dashboard',
        ],
        [
            'label' => 'Vehicle Registry',
            'route' => route('vehicle-registry.index'),
            'active' => request()->routeIs('vehicle-registry.*'),
            'icon' => 'vehicle',
        ],
        [
            'label' => 'RFID Desk',
            'route' => route('rfid-scans.index'),
            'active' => request()->routeIs('rfid-scans.*'),
            'icon' => 'rfid',
        ],
        [
            'label' => 'Live Monitoring',
            'route' => route('monitoring.index'),
            'active' => request()->routeIs('monitoring.*'),
            'icon' => 'monitor',
        ],
        [
            'label' => 'Guest Monitoring',
            'route' => route('guest-observations.index'),
            'active' => request()->routeIs('guest-observations.*'),
            'icon' => 'guest',
        ],
        [
            'label' => 'Event Logs',
            'route' => route('vehicle-events.index'),
            'active' => request()->routeIs('vehicle-events.*'),
            'icon' => 'logs',
        ],
        [
            'label' => 'Reports',
            'route' => route('reports.index'),
            'active' => request()->routeIs('reports.*'),
            'icon' => 'reports',
        ],
    ];

    if ($isAdmin) {
        $mainItems[] = [
            'label' => 'Settings',
            'route' => route('settings.index'),
            'active' => request()->routeIs('settings.*'),
            'icon' => 'settings',
        ];
    }

    $advancedItems = $isAdmin
        ? [
            [
                'label' => 'Calibration',
                'route' => route('calibration.index'),
                'active' => request()->routeIs('calibration.*'),
                'icon' => 'calibration',
            ],
            [
                'label' => 'Review Queue',
                'route' => route('manual-review.index'),
                'active' => request()->routeIs('manual-review.*'),
                'icon' => 'review',
            ],
            [
                'label' => 'Incomplete Records',
                'route' => route('incomplete-records.index'),
                'active' => request()->routeIs('incomplete-records.*'),
                'icon' => 'incomplete',
            ],
            [
                'label' => 'System Status',
                'route' => route('system-status.index'),
                'active' => request()->routeIs('system-status.*'),
                'icon' => 'status',
            ],
        ]
        : [];

    $navIcon = static function (string $icon): string {
        return match ($icon) {
            'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h4A1.5 1.5 0 0 1 11 5.5v4A1.5 1.5 0 0 1 9.5 11h-4A1.5 1.5 0 0 1 4 9.5zm9 0A1.5 1.5 0 0 1 14.5 4h4A1.5 1.5 0 0 1 20 5.5v7a1.5 1.5 0 0 1-1.5 1.5h-4a1.5 1.5 0 0 1-1.5-1.5zm-9 9A1.5 1.5 0 0 1 5.5 13h4a1.5 1.5 0 0 1 1.5 1.5v4A1.5 1.5 0 0 1 9.5 20h-4A1.5 1.5 0 0 1 4 18.5zm9 3A1.5 1.5 0 0 1 14.5 16h4a1.5 1.5 0 0 1 0 3h-4A1.5 1.5 0 0 1 13 17.5z"/></svg>',
            'monitor' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 5A2.5 2.5 0 0 0 2 7.5v7A2.5 2.5 0 0 0 4.5 17H9l-1.2 2H6.5a1 1 0 0 0 0 2h11a1 1 0 1 0 0-2h-1.3L15 17h4.5a2.5 2.5 0 0 0 2.5-2.5v-7A2.5 2.5 0 0 0 19.5 5zm0 2h15a.5.5 0 0 1 .5.5v7a.5.5 0 0 1-.5.5h-15a.5.5 0 0 1-.5-.5v-7a.5.5 0 0 1 .5-.5"/></svg>',
            'logs' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3.5A2.5 2.5 0 0 0 3.5 6v12A2.5 2.5 0 0 0 6 20.5h12a2.5 2.5 0 0 0 2.5-2.5V9.2a2.5 2.5 0 0 0-.73-1.77l-3.2-3.2A2.5 2.5 0 0 0 14.8 3.5zm0 2h8v3a2 2 0 0 0 2 2h2.5V18a.5.5 0 0 1-.5.5H6a.5.5 0 0 1-.5-.5V6a.5.5 0 0 1 .5-.5m1.5 7a1 1 0 0 0 0 2h9a1 1 0 1 0 0-2zm0 4a1 1 0 0 0 0 2h6a1 1 0 1 0 0-2z"/></svg>',
            'review' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9 1 1 0 1 0-2 0 7 7 0 1 1-7-7 1 1 0 1 0 0-2m5.3 2.3L12 10.59l-2.3-2.3a1 1 0 0 0-1.4 1.42l3 3a1 1 0 0 0 1.4 0l6-6a1 1 0 1 0-1.4-1.42"/></svg>',
            'vehicle' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 6A2.5 2.5 0 0 0 3 8.5v6A2.5 2.5 0 0 0 5.5 17H6v1a1 1 0 1 0 2 0v-1h8v1a1 1 0 1 0 2 0v-1h.5A2.5 2.5 0 0 0 21 14.5v-6A2.5 2.5 0 0 0 18.5 6h-13M7 9.5a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 7 9.5m10 0a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 17 9.5M8.5 7.5l1-2h5l1 2z"/></svg>',
            'rfid' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5a1 1 0 0 1 1 1v12a1 1 0 1 1-2 0V6a1 1 0 0 1 1-1m4 2a1 1 0 0 1 1 1v8a1 1 0 1 1-2 0V8a1 1 0 0 1 1-1m4-2a1 1 0 0 1 1 1v12a1 1 0 1 1-2 0V6a1 1 0 0 1 1-1m4 3a1 1 0 0 1 1 1v6a1 1 0 1 1-2 0V9a1 1 0 0 1 1-1"/></svg>',
            'calibration' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4a2 2 0 0 0-2 2v3a1 1 0 1 0 2 0V6h3a1 1 0 1 0 0-2zm9 0a1 1 0 1 0 0 2h3v3a1 1 0 1 0 2 0V6a2 2 0 0 0-2-2zm4 11a1 1 0 0 0-1 1v3h-3a1 1 0 1 0 0 2h3a2 2 0 0 0 2-2v-3a1 1 0 0 0-1-1M5 15a1 1 0 0 0-1 1v3a2 2 0 0 0 2 2h3a1 1 0 1 0 0-2H6v-3a1 1 0 0 0-1-1m3.5-4a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/></svg>',
            'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.4 3.6-.3 1.6a7.7 7.7 0 0 0-1.4.8L6.2 5.2a1.2 1.2 0 0 0-1.6.2L3.2 6.8a1.2 1.2 0 0 0-.2 1.6l.8 1.5a7.7 7.7 0 0 0-.8 1.4l-1.6.3A1.2 1.2 0 0 0 .5 13v2a1.2 1.2 0 0 0 .9 1.2l1.6.3a7.7 7.7 0 0 0 .8 1.4l-.8 1.5a1.2 1.2 0 0 0 .2 1.6l1.4 1.4a1.2 1.2 0 0 0 1.6.2l1.5-.8a7.7 7.7 0 0 0 1.4.8l.3 1.6a1.2 1.2 0 0 0 1.2.9h2a1.2 1.2 0 0 0 1.2-.9l.3-1.6a7.7 7.7 0 0 0 1.4-.8l1.5.8a1.2 1.2 0 0 0 1.6-.2l1.4-1.4a1.2 1.2 0 0 0 .2-1.6l-.8-1.5a7.7 7.7 0 0 0 .8-1.4l1.6-.3A1.2 1.2 0 0 0 23.5 15v-2a1.2 1.2 0 0 0-.9-1.2l-1.6-.3a7.7 7.7 0 0 0-.8-1.4l.8-1.5a1.2 1.2 0 0 0-.2-1.6l-1.4-1.4a1.2 1.2 0 0 0-1.6-.2l-1.5.8a7.7 7.7 0 0 0-1.4-.8l-.3-1.6A1.2 1.2 0 0 0 13 2.5h-2a1.2 1.2 0 0 0-1.2.9M12 8.5A3.5 3.5 0 1 1 8.5 12 3.5 3.5 0 0 1 12 8.5"/></svg>',
            'guest' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a4 4 0 1 0 4 4 4 4 0 0 0-4-4m0 10c-4.4 0-8 2-8 4.5V20a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2.5C20 15 16.4 13 12 13m8.2-8.5a1 1 0 0 0-1.4 1.4A3 3 0 0 1 18 8a1 1 0 1 0 2 0 5 5 0 0 0-1.8-3.5"/></svg>',
            'reports' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4.5A1.5 1.5 0 0 1 5.5 3h13A1.5 1.5 0 0 1 20 4.5v15a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 19.5zm3 3a1 1 0 0 0 0 2h10a1 1 0 1 0 0-2zm0 4a1 1 0 0 0 0 2h6a1 1 0 1 0 0-2zm0 4a1 1 0 0 0 0 2h8a1 1 0 1 0 0-2z"/></svg>',
            'incomplete' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2m0 5a1 1 0 0 1 1 1v5a1 1 0 0 1-2 0V8a1 1 0 0 1 1-1m0 10a1.25 1.25 0 1 1 1.25-1.25A1.25 1.25 0 0 1 12 17"/></svg>',
            'status' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9A9 9 0 0 0 12 3m-3 9a1 1 0 0 1 2 0v3a1 1 0 0 1-2 0zm4-2a1 1 0 0 1 2 0v5a1 1 0 0 1-2 0zm4-3a1 1 0 0 1 2 0v8a1 1 0 0 1-2 0z"/></svg>',
            'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 3.75A2.75 2.75 0 0 0 7.25 6.5v2.25a1 1 0 1 0 2 0V6.5c0-.41.34-.75.75-.75h6.5c.41 0 .75.34.75.75v11c0 .41-.34.75-.75.75H10a.75.75 0 0 1-.75-.75v-2.25a1 1 0 1 0-2 0v2.25A2.75 2.75 0 0 0 10 20.25h6.5A2.75 2.75 0 0 0 19.25 17.5v-11A2.75 2.75 0 0 0 16.5 3.75zm-5.28 7.53a1 1 0 0 0 0 1.44l3 2.97a1 1 0 0 0 1.4-1.42l-1.26-1.24H14a1 1 0 1 0 0-2H7.86l1.26-1.25a1 1 0 0 0-1.4-1.42z"/></svg>',
            default => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/></svg>',
        };
    };
@endphp

<nav class="sidebar-nav">
    <div class="sidebar-nav-sections">
        <div class="nav-section">
            <p class="nav-section-title">Main Menu</p>

            @foreach ($mainItems as $item)
                <a href="{{ $item['route'] }}" class="nav-link {{ $item['active'] ? 'is-active' : '' }}">
                    <span class="nav-icon">{!! $navIcon($item['icon']) !!}</span>
                    <span class="nav-label">{{ $item['label'] }}</span>
                </a>
            @endforeach
        </div>

        @if ($isAdmin)
            <div class="nav-section">
                <p class="nav-section-title">Advanced</p>

                @foreach ($advancedItems as $item)
                    <a href="{{ $item['route'] }}" class="nav-link {{ $item['active'] ? 'is-active' : '' }}">
                        <span class="nav-icon">{!! $navIcon($item['icon']) !!}</span>
                        <span class="nav-label">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="sidebar-footer">
        <div class="sidebar-account">
            <span class="sidebar-account-label">Logged in as</span>
            <strong>{{ auth()->user()->name ?? 'System User' }}</strong>
            <span class="sidebar-account-email">{{ auth()->user()->email }}</span>
        </div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sidebar-logout-button button-full">
                <span class="nav-icon">{!! $navIcon('logout') !!}</span>
                <span class="nav-label">Logout</span>
            </button>
        </form>
    </div>
</nav>
