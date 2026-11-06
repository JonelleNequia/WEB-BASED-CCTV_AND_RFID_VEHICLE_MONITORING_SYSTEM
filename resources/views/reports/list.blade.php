@php
    $cssText = fn (string $text): string => '"'.str_replace(['\\', '"', '<', "\n", "\r"], ['\\\\', '\\"', '\\3C ', ' ', ' '], $text).'"';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report['title'] }}</title>
    <link rel="stylesheet" href="{{ asset('css/activity-report.css') }}">
    <link rel="stylesheet" href="{{ asset('css/list-report.css') }}?v={{ filemtime(public_path('css/list-report.css')) }}">
    <style>
        @page {
            @top-left { content: {!! $cssText($schoolName.' · '.$report['title']) !!}; }
            @bottom-left { content: {!! $cssText('Printed '.$printedAt) !!}; }
        }
        @page :first { @top-left { content: none; } }
    </style>
</head>
<body class="activity-report list-report" data-autoprint="{{ $autoprint ? '1' : '0' }}">
    <div class="report-toolbar">
        <a href="{{ $backUrl }}" class="report-back">← {{ $backLabel }}</a>
        @if ($report['supportsImages'])
            <form method="GET" action="{{ url()->current() }}" class="report-options" data-report-options>
                @foreach ($optionsQuery as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <label><input type="checkbox" name="snapshots" value="1" @checked($snapshots) data-report-snapshots> Include images</label>
                <noscript><button type="submit" class="report-button">Apply</button></noscript>
            </form>
        @endif
        <span class="report-toolbar-spacer"></span>
        <button type="button" class="report-button" data-report-print>Print</button>
        <button type="button" class="report-button report-button-primary" data-report-pdf>Save as PDF</button>
        <p class="report-hint" data-report-pdf-hint hidden>In the print window, set <strong>Destination</strong> to <strong>Save as PDF</strong>. It is the same layout as the printout.</p>
    </div>

    <main class="report-sheet">
        <header class="report-header">
            <p class="report-school">{{ $schoolName }}</p>
            <p class="report-system">{{ $systemName }}</p>
            <h1>{{ $report['title'] }}</h1>
            <dl class="report-meta">
                <div><dt>Filters</dt><dd data-report-filters>{{ $report['filtersLabel'] }}</dd></div>
                <div><dt>Matching records</dt><dd data-report-count>{{ number_format($report['rows']->count()) }}</dd></div>
                <div><dt>Printed</dt><dd>{{ $printedAt }}@if ($preparedBy !== '') by {{ $preparedBy }}@endif</dd></div>
            </dl>
        </header>

        <section class="report-summary" aria-label="Summary">
            <h2>Summary</h2>
            <div class="report-summary-grid">
                @foreach ($report['summaries'] as $heading => $summary)
                    <table class="summary-table">
                        <caption>{{ $heading }}</caption>
                        <thead><tr><th>{{ $heading }}</th><th>Records</th></tr></thead>
                        <tbody>
                            @forelse ($summary as $label => $count)
                                <tr><th scope="row">{{ $label }}</th><td>{{ number_format($count) }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="summary-empty">No records</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot><tr><th scope="row">Total</th><td>{{ number_format($report['rows']->count()) }}</td></tr></tfoot>
                    </table>
                @endforeach
            </div>
        </section>

        <section class="report-records" aria-label="Records">
            <h2>Records</h2>
            @if ($report['rows']->isEmpty())
                <p class="report-empty">No records match these filters.</p>
            @else
                <table class="records-table">
                    <thead><tr>
                        @if ($snapshots)<th class="col-snapshot">Image</th>@endif
                        @foreach ($report['columns'] as $key => $label)<th class="report-cell-{{ $key }}">{{ $label }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($report['rows'] as $row)
                            <tr data-record-id="{{ $row['id'] }}">
                                @if ($snapshots)
                                    <td class="col-snapshot">
                                        @if ($row['image_url'])
                                            <figure>
                                                <img src="{{ $row['image_url'] }}" alt="{{ $row['image_label'] }} for record {{ $row['id'] }}" data-report-image>
                                                <figcaption>{{ $row['image_label'] }}</figcaption>
                                            </figure>
                                        @endif
                                        <span class="no-snapshot" @if ($row['image_url']) hidden @endif>No image</span>
                                    </td>
                                @endif
                                @foreach ($row['cells'] as $key => $value)<td class="report-cell-{{ $key }}">{{ $value }}</td>@endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="report-signatures" aria-label="Signatures">
            <div class="signature"><span class="signature-label">Prepared by:</span><span class="signature-line">{{ $preparedBy }}</span><span class="signature-caption">Signature over printed name</span></div>
            <div class="signature"><span class="signature-label">Noted by:</span><span class="signature-line"></span><span class="signature-caption">Signature over printed name</span></div>
        </section>
    </main>
    <script src="{{ asset('js/list-report.js') }}?v={{ filemtime(public_path('js/list-report.js')) }}"></script>
    <script src="{{ asset('js/activity-report.js') }}"></script>
</body>
</html>
