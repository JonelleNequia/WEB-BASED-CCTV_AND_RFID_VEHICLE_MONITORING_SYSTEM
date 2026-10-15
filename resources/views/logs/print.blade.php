{{--
    ROI work, Phase 7: Vehicle Activity Report (Activity Logs › Print).
    A4 landscape, no sidebar, buttons or filters on paper. Print and Save as
    PDF both use the browser's print window on this page, so the PDF has the
    same layout as the printout. The table header repeats on every page, a
    row is never split, and every page says "Page X of Y".
--}}
@php
    // Text for the page margins (CSS strings).
    $cssText = fn (string $text): string => '"'.str_replace(['\\', '"', '<', "\n", "\r"], ['\\\\', '\\"', '\\3C ', ' ', ' '], $text).'"';
    $summaries = ['Category' => $byCategory, 'Gate' => $byGate];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $documentTitle }}</title>
    <link rel="stylesheet" href="{{ asset('css/activity-report.css') }}">
    <style>
        @page {
            @top-left { content: {!! $cssText($schoolName.' · Vehicle Activity Report') !!}; }
            @top-right { content: {!! $cssText($dateRange) !!}; }
            @bottom-left { content: {!! $cssText('Printed '.$printedAt) !!}; }
        }

        @page :first {
            @top-left { content: none; }
            @top-right { content: none; }
        }
    </style>
</head>
<body @class(['activity-report', 'with-snapshots' => $snapshots]) data-autoprint="{{ $autoprint ? '1' : '0' }}">
    <div class="report-toolbar">
        <a href="{{ $backUrl }}" class="report-back">← Activity Logs</a>
        <form method="GET" action="{{ route('logs.print') }}" class="report-options" data-report-options>
            @foreach ($optionsQuery as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <label>
                <input type="checkbox" name="snapshots" value="1" @checked($snapshots) data-report-snapshots>
                Include snapshots
            </label>
            <noscript><button type="submit" class="report-button">Apply</button></noscript>
        </form>
        <span class="report-toolbar-spacer"></span>
        <button type="button" class="report-button" data-report-print>Print</button>
        <button type="button" class="report-button report-button-primary" data-report-pdf>Save as PDF</button>
        <p class="report-hint" data-report-pdf-hint hidden>
            In the print window, set <strong>Destination</strong> to <strong>Save as PDF</strong>. It is the same layout as the printout.
        </p>
    </div>

    <main class="report-sheet">
        <header class="report-header">
            <p class="report-school">{{ $schoolName }}</p>
            <p class="report-system">{{ $systemName }}</p>
            <h1>Vehicle Activity Report</h1>
            <dl class="report-meta">
                <div><dt>Date range</dt><dd data-report-range>{{ $dateRange }}</dd></div>
                <div><dt>Filters</dt><dd data-report-filters>{{ $filtersLabel }}</dd></div>
                <div><dt>Records</dt><dd>{{ number_format($rows->count()) }}</dd></div>
                <div><dt>Printed</dt><dd>{{ $printedAt }}@if ($preparedBy !== '') by {{ $preparedBy }}@endif</dd></div>
            </dl>
        </header>

        <section class="report-summary" aria-label="Summary">
            <h2>Summary</h2>
            <div class="report-summary-grid">
                @foreach ($summaries as $heading => $summary)
                    <table class="summary-table" data-summary="{{ strtolower($heading) }}">
                        <caption>IN / OUT by {{ strtolower($heading) }}</caption>
                        <thead>
                            <tr><th>{{ $heading }}</th><th>IN</th><th>OUT</th><th>Unknown</th><th>Total</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($summary['rows'] as $line)
                                <tr><th scope="row">{{ $line['label'] }}</th><td>{{ $line['in'] }}</td><td>{{ $line['out'] }}</td><td>{{ $line['unknown'] }}</td><td>{{ $line['total'] }}</td></tr>
                            @empty
                                <tr><td colspan="5" class="summary-empty">No records</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr><th scope="row">Total</th><td>{{ $summary['total']['in'] }}</td><td>{{ $summary['total']['out'] }}</td><td>{{ $summary['total']['unknown'] }}</td><td>{{ $summary['total']['total'] }}</td></tr>
                        </tfoot>
                    </table>
                @endforeach
            </div>
            <p class="report-note">Unknown: the camera could not tell IN or OUT, or an RFID scan without a camera record.</p>
        </section>

        <section class="report-records" aria-label="Records">
            <h2>Records</h2>
            @if ($rows->isEmpty())
                <p class="report-empty">No records for this report.</p>
            @else
                <table class="records-table">
                    <thead>
                        <tr>
                            <th class="col-no">#</th>
                            @if ($snapshots)
                                <th class="col-snapshot">Snapshot</th>
                            @endif
                            <th class="col-time">Time</th>
                            <th class="col-plate">Plate</th>
                            <th class="col-owner">Name / Owner</th>
                            <th class="col-category">Category</th>
                            <th class="col-type">Type</th>
                            <th class="col-direction">Direction</th>
                            <th class="col-gate">Gate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="col-no">{{ $loop->iteration }}</td>
                                @if ($snapshots)
                                    <td class="col-snapshot">
                                        @if ($row['image_url'])
                                            <img src="{{ $row['image_url'] }}" alt="Snapshot of {{ $row['plate'] }}">
                                        @else
                                            <span class="no-snapshot">No snapshot</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="col-time">{{ $row['time'] }}</td>
                                <td class="col-plate"><strong>{{ $row['plate'] }}</strong></td>
                                <td class="col-owner">{{ $row['owner'] }}</td>
                                <td class="col-category">{{ $row['category'] }}</td>
                                <td class="col-type">{{ $row['type'] }}</td>
                                <td class="col-direction direction-{{ $row['direction_key'] }}">{{ $row['direction'] }}</td>
                                <td class="col-gate">{{ $row['gate'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="report-signatures" aria-label="Signatures">
            <div class="signature">
                <span class="signature-label">Prepared by:</span>
                <span class="signature-line">{{ $preparedBy }}</span>
                <span class="signature-caption">Signature over printed name</span>
            </div>
            <div class="signature">
                <span class="signature-label">Noted by:</span>
                <span class="signature-line"></span>
                <span class="signature-caption">Signature over printed name</span>
            </div>
        </section>
    </main>

    <script src="{{ asset('js/activity-report.js') }}"></script>
</body>
</html>
