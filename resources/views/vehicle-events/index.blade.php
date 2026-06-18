@extends('layouts.app')

@section('title', 'Event Logs | PHILCST Vehicle Access Monitoring')
@section('page-title', 'Event Logs')
@section('page-description', 'Review vehicle events, guest observations, and report-ready filtered records from one searchable list.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Central Record</span>
            <h3>Vehicle operations logs</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">RFID-first logs</span>
                <span class="chip chip-soft">Filtered list is the report</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('stations.entrance') }}" class="button button-secondary">Entrance Station</a>
            <a href="{{ route('stations.exit') }}" class="button button-secondary">Exit Station</a>
            @if (auth()->user()?->isAdmin())
                <a href="{{ route('vehicle-registry.index') }}" class="button button-secondary">Registry</a>
                <a href="{{ route('guest-observations.index') }}" class="button button-secondary">Guest Monitoring</a>
                <a href="{{ route('vehicle-events.create') }}" class="button button-primary">Quick Manual Log</a>
            @endif
        </div>
    </section>

    <div class="page-grid cards-5">
        <article class="stat-card stat-card-brand">
            <span class="stat-card-label">{{ $selectedPeriodLabel }} Logs</span>
            <strong>{{ $eventLogSummary['total'] }}</strong>
            <p>All visible records in the current range.</p>
        </article>

        <article class="stat-card stat-card-success">
            <span class="stat-card-label">Entries</span>
            <strong>{{ $eventLogSummary['entries'] }}</strong>
            <p>ENTRY logs in the current range.</p>
        </article>

        <article class="stat-card stat-card-brand-soft">
            <span class="stat-card-label">Exits</span>
            <strong>{{ $eventLogSummary['exits'] }}</strong>
            <p>EXIT logs in the current range.</p>
        </article>

        <article class="stat-card stat-card-warning">
            <span class="stat-card-label">Guests</span>
            <strong>{{ $eventLogSummary['guests'] }}</strong>
            <p>Guest observations in the current range.</p>
        </article>

        <article class="stat-card">
            <span class="stat-card-label">RFID Only</span>
            <strong>{{ $eventLogSummary['rfid'] }}</strong>
            <p>RFID scan records without linked events.</p>
        </article>
    </div>

    <section class="panel printable-report-panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Filters</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain log filters',
                        'text' => 'Filter by plate, log type, workflow status, or date range. Guest and review records remain searchable here.',
                    ])
                </div>
            </div>
        </div>

        <form method="GET" action="{{ route('vehicle-events.index') }}" class="form-grid filter-grid">
            <div class="field">
                <label for="plate_text">Plate</label>
                <input id="plate_text" type="text" name="plate_text" value="{{ $filters['plate_text'] ?? '' }}" placeholder="ABC-1234">
            </div>

            <div class="field">
                <label for="period">Timeframe</label>
                <select id="period" name="period">
                    <option value="">All / Custom Dates</option>
                    @foreach ($periodOptions as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['period'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="vehicle_owner_name">Vehicle Owner Name</label>
                <input id="vehicle_owner_name" type="text" name="vehicle_owner_name" value="{{ $filters['vehicle_owner_name'] ?? '' }}" placeholder="Juan Dela Cruz">
            </div>

            <div class="field">
                <label for="category">Category</label>
                <select id="category" name="category">
                    <option value="">All</option>
                    @foreach ($categoryOptions as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="event_type">Log Type</label>
                <select id="event_type" name="event_type">
                    <option value="">All</option>
                    <option value="ENTRY" @selected(($filters['event_type'] ?? '') === 'ENTRY')>ENTRY</option>
                    <option value="EXIT" @selected(($filters['event_type'] ?? '') === 'EXIT')>EXIT</option>
                    <option value="GUEST" @selected(($filters['event_type'] ?? '') === 'GUEST')>GUEST</option>
                    <option value="RFID" @selected(($filters['event_type'] ?? '') === 'RFID')>RFID</option>
                </select>
            </div>

            <div class="field">
                <label for="match_status">Status</label>
                <select id="match_status" name="match_status">
                    <option value="">All</option>
                    @foreach (['open' => 'Entry', 'closed' => 'Exit', 'matched' => 'Matched', 'unmatched' => 'Unmatched', 'verified' => 'Verified'] as $status => $label)
                        <option value="{{ $status }}" @selected(($filters['match_status'] ?? '') === $status)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="date_from">From</label>
                <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
            </div>

            <div class="field">
                <label for="date_to">To</label>
                <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
            </div>

            <div class="field field-actions">
                <div class="button-row">
                    <button type="submit" class="button button-primary">Apply Filters</button>
                    <a href="{{ route('vehicle-events.index') }}" class="button button-secondary">Reset</a>
                </div>
            </div>
        </form>

        <div class="report-action-bar">
            <div>
                <strong>Quick Timeframes</strong>
                <span>Open the same log list by day, week, month, or year.</span>
            </div>
            <div class="button-row">
                @foreach ($periodOptions as $period => $label)
                    <a href="{{ route('vehicle-events.index', ['period' => $period]) }}" class="button button-secondary button-sm">{{ $label }}</a>
                @endforeach
            </div>
        </div>

        <div class="report-action-bar">
            <div>
                <strong>Print Reports</strong>
                <span>Print compact Event Logs by all records, day, week, or year.</span>
            </div>
            <div class="button-row">
                @foreach ($printReports as $reportKey => $report)
                    <button type="button" class="button button-secondary button-sm" data-event-log-report-print="{{ $reportKey }}">
                        Print {{ $report['label'] }}
                    </button>
                @endforeach
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <div class="panel-title-row">
                    <h3>Event Logs</h3>
                    @include('layouts.partials.help', [
                        'label' => 'Explain vehicle logs table',
                        'text' => 'RFID-linked records are the normal operational path. Camera-linked details appear only when available for support.',
                    ])
                </div>
            </div>
            <span class="chip chip-soft">{{ $logs->total() }} total</span>
        </div>

        <div class="event-log-card-list event-log-list-view" data-event-log-list>
            @forelse ($logs as $log)
                <article class="event-log-card event-log-list-item">
                    <div class="event-log-card-top">
                        <div class="event-log-title-block">
                            <span class="station-log-badge">{{ $log['event_type'] }}</span>
                            <div>
                                <strong>{{ $log['plate_number'] ?: 'GUEST' }}</strong>
                                <span>{{ $log['summary_label'] }}</span>
                            </div>
                        </div>

                        <div class="event-log-row-actions">
                            <span class="badge badge-{{ $log['status_badge_class'] }}">{{ $log['status_label'] }}</span>
                            <button type="button" class="button button-secondary button-sm" data-event-log-view="{{ $loop->index }}">
                                Details
                            </button>
                            <button type="button" class="button button-primary button-sm" data-event-log-print="{{ $loop->index }}">Print</button>
                            <a href="{{ $log['export_url'] }}" class="button button-secondary button-sm">CSV</a>
                        </div>
                    </div>

                    <div class="event-log-body">
                        <div class="event-log-preview" @unless($log['image_url']) data-empty-preview @endunless>
                            @if ($log['image_url'])
                                <img src="{{ $log['image_url'] }}" alt="{{ $log['record_type_label'] }} snapshot">
                            @else
                                No Image
                            @endif
                        </div>

                        <div class="event-log-summary-panel">
                            <div class="event-log-summary-line">
                                <span>{{ $log['station_label'] }}</span>
                                <span>{{ $log['display_time'] }}</span>
                                <span>{{ $log['source_label'] }}</span>
                            </div>
                            <p>{{ $log['vehicle_type'] }} | {{ $log['vehicle_color'] }} | {{ $log['category_label'] }}</p>
                        </div>
                    </div>
                </article>
            @empty
                <div class="empty-state">
                    <h4>No records matched the current filters</h4>
                    <p>Adjust the report filters to widen the visible list.</p>
                </div>
            @endforelse
        </div>

        @include('layouts.partials.pagination', ['paginator' => $logs])
    </section>

    <div class="modal-backdrop is-hidden" data-event-log-modal aria-hidden="true">
        <div class="modal-panel event-log-modal-panel" role="dialog" aria-modal="true" aria-labelledby="event-log-modal-title">
            <div class="modal-header">
                <div>
                    <span class="panel-kicker" data-event-log-modal-type>Event Log</span>
                    <h3 id="event-log-modal-title">Log Details</h3>
                </div>
                <button type="button" class="modal-close" data-event-log-modal-close aria-label="Close details">×</button>
            </div>

            <div class="event-log-modal-body">
                <div class="event-log-modal-image" data-event-log-modal-image-wrap hidden>
                    <img src="" alt="Vehicle log snapshot" data-event-log-modal-image>
                </div>

                <div class="event-log-detail-grid" data-event-log-modal-details></div>
            </div>

            <div class="modal-actions">
                <a href="#" class="button button-primary button-sm" data-event-log-modal-link>Open Full Record</a>
                <button type="button" class="button button-primary button-sm" data-event-log-modal-print>Print</button>
                <a href="#" class="button button-secondary button-sm" data-event-log-modal-export>CSV</a>
                <button type="button" class="button button-secondary button-sm" data-event-log-modal-close>Close</button>
            </div>
        </div>
    </div>

    <section class="event-log-print-sheet" data-event-log-print-sheet hidden></section>

    <script id="event-log-modal-data" type="application/json">{!! json_encode($logs->getCollection()->values(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    <script id="event-log-report-data" type="application/json" data-payload="{{ base64_encode(json_encode($printReports, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) }}"></script>
    <script id="event-log-realtime-data" type="application/json">{!! json_encode([
        'recentLogsUrl' => request()->except('page') === [] ? route('api.recent-event-logs', ['limit' => 10]) : null,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const dataNode = document.getElementById('event-log-modal-data');
            const reportNode = document.getElementById('event-log-report-data');
            const realtimeNode = document.getElementById('event-log-realtime-data');
            const modal = document.querySelector('[data-event-log-modal]');
            const title = document.getElementById('event-log-modal-title');
            const type = document.querySelector('[data-event-log-modal-type]');
            const detailGrid = document.querySelector('[data-event-log-modal-details]');
            const imageWrap = document.querySelector('[data-event-log-modal-image-wrap]');
            const image = document.querySelector('[data-event-log-modal-image]');
            const link = document.querySelector('[data-event-log-modal-link]');
            const modalPrintButton = document.querySelector('[data-event-log-modal-print]');
            const modalExportLink = document.querySelector('[data-event-log-modal-export]');
            const printSheet = document.querySelector('[data-event-log-print-sheet]');
            const list = document.querySelector('[data-event-log-list]');
            const realtimeConfig = realtimeNode ? JSON.parse(realtimeNode.textContent || '{}') : {};
            let logs = dataNode ? JSON.parse(dataNode.textContent || '[]') : [];
            const printReports = reportNode ? JSON.parse(atob(reportNode.dataset.payload || 'e30=')) : {};
            let activeModalLog = null;

            if (!modal || !title || !type || !detailGrid || !imageWrap || !image || !link || !modalPrintButton || !modalExportLink || !printSheet) {
                return;
            }

            const detailsFor = (log) => [
                ['Owner', log.owner_name],
                ['Vehicle', log.vehicle_type],
                ['Color', log.vehicle_color],
                ['Category', log.category_label],
                ['Source', log.source_label],
                ['Station / Camera', log.station_label],
                ['RFID Tag', log.rfid_tag_uid],
                ['State', log.state_label],
                ['Status', log.status_label],
                ['Match', log.match_label],
                ['Time', log.display_time],
            ];

            const printDetailsFor = (log) => [
                ['Record', `${log.record_type_label || 'Log'} #${log.id || 'N/A'}`],
                ['Log Type', log.event_type],
                ['Plate', log.plate_number || 'GUEST'],
                ['Owner', log.owner_name],
                ['Vehicle', log.vehicle_type],
                ['Color', log.vehicle_color],
                ['Category', log.category_label],
                ['Source', log.source_label],
                ['Station', log.station_label],
                ['RFID Tag', log.rfid_tag_uid],
                ['State', log.state_label],
                ['Status', log.status_label],
                ['Match', log.match_label],
                ['Time', log.display_time],
            ];

            const appendText = (parent, tagName, text, className = null) => {
                const node = document.createElement(tagName);

                if (className) {
                    node.className = className;
                }

                node.textContent = text || '';
                parent.appendChild(node);

                return node;
            };

            const buildLogCard = (log, index) => {
                const card = document.createElement('article');
                const top = document.createElement('div');
                const titleBlock = document.createElement('div');
                const badge = document.createElement('span');
                const titleText = document.createElement('div');
                const actions = document.createElement('div');
                const statusBadge = document.createElement('span');
                const viewButton = document.createElement('button');
                const printButton = document.createElement('button');
                const csvLink = document.createElement('a');
                const body = document.createElement('div');
                const preview = document.createElement('div');
                const summary = document.createElement('div');
                const summaryLine = document.createElement('div');

                card.className = 'event-log-card event-log-list-item';
                top.className = 'event-log-card-top';
                titleBlock.className = 'event-log-title-block';
                badge.className = 'station-log-badge';
                actions.className = 'event-log-row-actions';
                statusBadge.className = `badge badge-${log.status_badge_class || 'secondary'}`;
                viewButton.className = 'button button-secondary button-sm';
                printButton.className = 'button button-primary button-sm';
                csvLink.className = 'button button-secondary button-sm';
                body.className = 'event-log-body';
                preview.className = 'event-log-preview';
                summary.className = 'event-log-summary-panel';
                summaryLine.className = 'event-log-summary-line';

                badge.textContent = log.event_type || 'LOG';
                appendText(titleText, 'strong', log.plate_number || 'GUEST');
                appendText(titleText, 'span', log.summary_label || '');
                titleBlock.append(badge, titleText);

                statusBadge.textContent = log.status_label || 'Recorded';
                viewButton.type = 'button';
                viewButton.dataset.eventLogView = String(index);
                viewButton.textContent = 'Details';
                printButton.type = 'button';
                printButton.dataset.eventLogPrint = String(index);
                printButton.textContent = 'Print';
                csvLink.href = log.export_url || '#';
                csvLink.textContent = 'CSV';
                actions.append(statusBadge, viewButton, printButton, csvLink);
                top.append(titleBlock, actions);

                if (log.image_url) {
                    const img = document.createElement('img');
                    img.src = log.image_url;
                    img.alt = `${log.record_type_label || 'Vehicle log'} snapshot`;
                    preview.appendChild(img);
                } else {
                    preview.dataset.emptyPreview = '';
                    preview.textContent = 'No Image';
                }

                appendText(summaryLine, 'span', log.station_label || 'No station');
                appendText(summaryLine, 'span', log.display_time || 'No time');
                appendText(summaryLine, 'span', log.source_label || 'No source');
                summary.appendChild(summaryLine);
                appendText(summary, 'p', `${log.vehicle_type || 'Vehicle'} | ${log.vehicle_color || 'N/A'} | ${log.category_label || 'N/A'}`);
                body.append(preview, summary);
                card.append(top, body);

                return card;
            };

            const printLog = (log) => {
                if (!log) {
                    return;
                }

                printSheet.innerHTML = '';

                const header = document.createElement('div');
                const kicker = document.createElement('span');
                const heading = document.createElement('h2');
                const meta = document.createElement('p');
                const grid = document.createElement('div');

                header.className = 'event-log-print-header';
                kicker.textContent = 'PHILCST Vehicle Monitoring';
                heading.textContent = `${log.event_type || 'LOG'} - ${log.plate_number || 'GUEST'}`;
                meta.textContent = `${log.record_type_label || 'Record'} #${log.id || 'N/A'}`;
                header.append(kicker, heading, meta);

                grid.className = 'event-log-print-grid';
                printDetailsFor(log).forEach(([label, value]) => {
                    const item = document.createElement('div');
                    const labelNode = document.createElement('span');
                    const valueNode = document.createElement('strong');

                    labelNode.textContent = label;
                    valueNode.textContent = value || 'N/A';
                    item.append(labelNode, valueNode);
                    grid.appendChild(item);
                });

                printSheet.append(header, grid);

                if (log.image_url) {
                    const imageBox = document.createElement('div');
                    const imageNode = document.createElement('img');

                    imageBox.className = 'event-log-print-image';
                    imageNode.src = log.image_url;
                    imageNode.alt = `${log.record_type_label || 'Vehicle log'} snapshot`;
                    imageBox.appendChild(imageNode);
                    printSheet.appendChild(imageBox);
                }

                printSheet.hidden = false;
                document.body.classList.add('is-printing-event-log');

                const cleanupPrint = () => {
                    document.body.classList.remove('is-printing-event-log');
                    printSheet.hidden = true;
                    printSheet.innerHTML = '';
                    window.removeEventListener('afterprint', cleanupPrint);
                };

                window.addEventListener('afterprint', cleanupPrint);
                window.print();
            };

            const printReport = (report) => {
                if (!report) {
                    return;
                }

                const rows = Array.isArray(report.rows) ? report.rows : [];
                printSheet.innerHTML = '';

                const header = document.createElement('div');
                const kicker = document.createElement('span');
                const heading = document.createElement('h2');
                const meta = document.createElement('p');

                header.className = 'event-log-print-header';
                kicker.textContent = 'PHILCST Vehicle Monitoring';
                heading.textContent = `Event Logs - ${report.label || 'Report'}`;
                meta.textContent = `${rows.length} record${rows.length === 1 ? '' : 's'}`;
                header.append(kicker, heading, meta);
                printSheet.appendChild(header);

                if (rows.length === 0) {
                    const empty = document.createElement('p');
                    empty.className = 'event-log-print-empty';
                    empty.textContent = 'No records found for this report.';
                    printSheet.appendChild(empty);
                } else {
                    const table = document.createElement('table');
                    const thead = document.createElement('thead');
                    const tbody = document.createElement('tbody');
                    const headerRow = document.createElement('tr');
                    const columns = [
                        ['timestamp', 'Timestamp'],
                        ['plate_number', 'Plate Number'],
                        ['owner_name', 'Owner Name'],
                        ['state', 'State'],
                        ['status', 'Status'],
                        ['rfid_tag', 'RFID Tag'],
                    ];

                    table.className = 'event-log-print-table';

                    columns.forEach(([, label]) => {
                        appendText(headerRow, 'th', label);
                    });

                    thead.appendChild(headerRow);

                    rows.forEach((row) => {
                        const tr = document.createElement('tr');

                        columns.forEach(([key]) => {
                            appendText(tr, 'td', row[key] || 'N/A');
                        });

                        tbody.appendChild(tr);
                    });

                    table.append(thead, tbody);
                    printSheet.appendChild(table);
                }

                printSheet.hidden = false;
                document.body.classList.add('is-printing-event-log');

                const cleanupPrint = () => {
                    document.body.classList.remove('is-printing-event-log');
                    printSheet.hidden = true;
                    printSheet.innerHTML = '';
                    window.removeEventListener('afterprint', cleanupPrint);
                };

                window.addEventListener('afterprint', cleanupPrint);
                window.print();
            };

            const renderEventLogs = (items) => {
                if (!list || !Array.isArray(items)) {
                    return;
                }

                logs = items;

                if (items.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'empty-state';
                    appendText(empty, 'h4', 'No records matched the current filters');
                    appendText(empty, 'p', 'Adjust the report filters to widen the visible list.');
                    list.replaceChildren(empty);

                    return;
                }

                list.replaceChildren(...items.map(buildLogCard));
            };

            const openModal = (log) => {
                activeModalLog = log;
                title.textContent = `${log.event_type} • ${log.plate_number || 'GUEST'}`;
                type.textContent = `${log.record_type_label} #${log.id}`;
                link.href = log.detail_url;
                modalExportLink.href = log.export_url || '#';
                detailGrid.innerHTML = '';

                detailsFor(log).forEach(([label, value]) => {
                    const item = document.createElement('div');
                    const labelNode = document.createElement('span');
                    const valueNode = document.createElement('strong');

                    labelNode.textContent = label;
                    valueNode.textContent = value || 'N/A';
                    item.append(labelNode, valueNode);
                    detailGrid.appendChild(item);
                });

                if (log.image_url) {
                    image.src = log.image_url;
                    imageWrap.hidden = false;
                } else {
                    image.removeAttribute('src');
                    imageWrap.hidden = true;
                }

                modal.classList.remove('is-hidden');
                modal.setAttribute('aria-hidden', 'false');
            };

            const closeModal = () => {
                modal.classList.add('is-hidden');
                modal.setAttribute('aria-hidden', 'true');
                activeModalLog = null;
            };

            document.addEventListener('click', (event) => {
                const reportButton = event.target.closest('[data-event-log-report-print]');

                if (reportButton) {
                    printReport(printReports[reportButton.dataset.eventLogReportPrint]);

                    return;
                }

                const printButton = event.target.closest('[data-event-log-print]');

                if (printButton) {
                    const log = logs[Number.parseInt(printButton.dataset.eventLogPrint, 10)];
                    printLog(log);

                    return;
                }

                const button = event.target.closest('[data-event-log-view]');

                if (!button) {
                    return;
                }

                const log = logs[Number.parseInt(button.dataset.eventLogView, 10)];

                if (log) {
                    openModal(log);
                }
            });

            modalPrintButton.addEventListener('click', () => {
                printLog(activeModalLog);
            });

            document.querySelectorAll('[data-event-log-modal-close]').forEach((button) => {
                button.addEventListener('click', closeModal);
            });

            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !modal.classList.contains('is-hidden')) {
                    closeModal();
                }
            });

            let eventPollInFlight = false;

            async function pollEventLogs() {
                if (!realtimeConfig.recentLogsUrl || eventPollInFlight) {
                    return;
                }

                eventPollInFlight = true;

                try {
                    const response = await fetch(realtimeConfig.recentLogsUrl, {
                        headers: {
                            Accept: 'application/json',
                        },
                    });

                    if (!response.ok) {
                        throw new Error('Event logs unavailable.');
                    }

                    const body = await response.json();
                    renderEventLogs(body.logs || []);
                } catch (error) {
                    // Keep the last good Event Logs state visible during polling failures.
                } finally {
                    eventPollInFlight = false;
                }
            }

            window.setInterval(pollEventLogs, 2000);
        });
    </script>
@endpush
