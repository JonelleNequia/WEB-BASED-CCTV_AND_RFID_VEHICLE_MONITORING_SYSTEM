@php
    $dataset = $reportDataset;
    $prefix = $dataset === 'guests' ? 'visitors' : 'registry';
    $reportQuery = ['tab' => $dataset === 'guests' ? 'records' : $dataset]
        + array_filter(request()->only(\App\Services\ListReportService::FILTERS[$dataset]), fn ($value) => filled($value));
@endphp
<div class="chip-group" role="group" aria-label="List reports">
    <a href="{{ route($prefix.'.print', $reportQuery) }}" class="button button-secondary button-sm" target="_blank" rel="noopener" data-auto-export data-auto-link="list-print">Print</a>
    <a href="{{ route($prefix.'.export.csv', $reportQuery) }}" class="button button-secondary button-sm" data-auto-export data-auto-link="list-csv">CSV</a>
</div>
