<?php

namespace App\Http\Controllers;

use App\Services\ListReportService;
use App\Support\DisplayTime;
use App\Support\PhilippineTime;
use App\Support\SpreadsheetCsv;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListReportController extends Controller
{
    public function print(Request $request, ListReportService $service): View
    {
        [$dataset, $filters, $listRoute, $tab] = $this->context($request);
        $report = $service->data($dataset, $filters);

        return view('reports.list', [
            'report' => $report, 'schoolName' => (string) config('monitoring.report.school_name'),
            'systemName' => (string) config('monitoring.report.system_name'), 'preparedBy' => (string) ($request->user()?->name ?? ''),
            'printedAt' => now(PhilippineTime::TIMEZONE)->format(DisplayTime::DATETIME).' (Asia/Manila)',
            'snapshots' => $report['supportsImages'] && $request->boolean('snapshots'), 'autoprint' => $request->boolean('autoprint'),
            'optionsQuery' => ['tab' => $tab] + array_filter($filters, fn ($value) => filled($value)),
            'backUrl' => route($listRoute, ['tab' => $tab] + $filters), 'backLabel' => $dataset === 'guests' ? 'Guests' : 'Registry',
        ]);
    }

    public function csv(Request $request, ListReportService $service): StreamedResponse
    {
        [$dataset, $filters] = $this->context($request);
        $report = $service->data($dataset, $filters);

        return response()->streamDownload(function () use ($report): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, array_values($report['columns']), ',', '"', '', "\r\n");
            foreach ($report['rows'] as $row) {
                fputcsv($stream, array_map(SpreadsheetCsv::cell(...), array_values($row['cells'])), ',', '"', '', "\r\n");
            }
            fclose($stream);
        }, $dataset.'-'.now(PhilippineTime::TIMEZONE)->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function context(Request $request): array
    {
        $dataset = $request->routeIs('registry.*') ? ($request->query('tab') === 'tags' ? 'tags' : 'vehicles') : 'guests';

        return [$dataset, $request->only(ListReportService::FILTERS[$dataset]),
            $dataset === 'guests' ? 'visitors.index' : 'registry.index', $dataset === 'guests' ? 'records' : $dataset];
    }
}
