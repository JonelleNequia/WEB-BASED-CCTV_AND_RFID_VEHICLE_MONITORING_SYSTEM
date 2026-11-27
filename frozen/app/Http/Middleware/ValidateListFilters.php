<?php

namespace App\Http\Middleware;

use App\Http\Controllers\VehicleEventController;
use App\Models\RfidTag;
use App\Rules\ValidGate;
use App\Support\VehicleCategory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ValidateListFilters
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()->getName();
        $tab = $request->query('tab');
        $tab = is_string($tab) ? $tab : '';
        $rules = ['tab' => ['nullable', 'string', 'max:30'], 'page' => ['nullable', 'integer', 'min:1']];
        $text = ['bail', 'nullable', 'string', 'max:255'];
        $choice = static fn (array $values): array => ['bail', 'nullable', 'string', Rule::in($values)];
        $registryReport = in_array($route, ['registry.print', 'registry.export.csv'], true);
        $guestReport = in_array($route, ['visitors.print', 'visitors.export.csv'], true);
        $listReport = $registryReport || $guestReport;
        if ($listReport) {
            $rules['tab'] = ['nullable', 'string', Rule::in($registryReport ? ['vehicles', 'tags'] : ['records'])];
            $rules += ['snapshots' => ['nullable', 'boolean'], 'autoprint' => ['nullable', 'boolean']];
        }
        if ($route === 'registry.index' || $registryReport) {
            $rules += $tab === 'tags'
                ? ['status' => $choice(RfidTag::STATUSES)]
                : ['q' => $text, 'category' => $choice([...array_keys(VehicleCategory::LABELS), ...VehicleCategory::LEGACY_REGISTERED]),
                    'state' => $choice(['inside', 'outside']), 'status' => $choice(['active', 'inactive'])];
        } elseif ($route === 'visitors.index' || $guestReport) {
            $rules += ['q' => $text, 'gate' => ['bail', 'nullable', 'string', new ValidGate(historical: true)],
                'record_filter' => $choice(array_keys(\App\Http\Controllers\VisitorController::RECORD_FILTERS)),
                'plate_status' => $choice(['pending', 'read', 'unreadable', 'corrected']),
                'status' => $choice(['active', 'duplicate', 'dismissed', 'all']), 'sort' => $choice(['entries', 'recent'])];
        } elseif ($route === 'logs.index' && $tab === 'scans') {
            $rules += ['history_q' => $text, 'scan_location' => ['bail', 'nullable', 'string', new ValidGate(historical: true)],
                'verification_status' => $choice(['anomaly', 'verified', 'pending', 'scan_only', 'unknown_tag', 'inactive_tag',
                    'unassigned_tag', 'inactive_vehicle', 'non_recurring_category', 'guest', 'guest_pass_lost', 'guest_pass_disabled'])];
        } elseif ($route === 'logs.index' && $tab === 'alerts') {
            $rules += ['q' => $text, 'type' => $choice(['anomaly', 'unknown_tag'])];
        } else {
            $rules += \App\Support\ActivityLogTimeWindow::rules($request->query());
            $rules += ['plate_text' => $text, 'vehicle_owner_name' => $text,
                'gate' => ['bail', 'nullable', 'string', new ValidGate(historical: true)],
                'period' => $choice(['today', 'week', 'month', 'year']),
                'event_type' => $choice([...array_keys(VehicleEventController::MOVEMENT_OPTIONS), 'GUEST']),
                'category' => $choice([...array_keys(VehicleCategory::LABELS), ...VehicleCategory::LEGACY_REGISTERED, 'guest', 'guest_pass']),
                'match_status' => $choice(['open', 'closed', 'matched', 'unmatched', 'verified', 'manual_review', 'requires_manual_review',
                    'pending_details', 'completed', 'no_pass_alert', 'no_pass_resolved']),
                'log_type' => $choice([...array_keys(VehicleEventController::LOG_TYPES), 'alerts'])];
            if ($route !== 'logs.index') {
                $rules += ['report' => $choice(array_keys(VehicleEventController::PRINT_REPORTS)),
                    'record_type' => $choice(['vehicle_event', 'guest_observation', 'rfid_scan', 'visitor_record']),
                    'record_id' => ['nullable', 'integer', 'min:1'],
                    'all' => ['nullable', 'boolean'], 'snapshots' => ['nullable', 'boolean'], 'autoprint' => ['nullable', 'boolean']];
            }
        }
        $input = Arr::only($request->query(), array_keys($rules));
        // Match existing controller case/boolean handling without changing
        // the incoming query or introducing additional filter behavior.
        foreach (['event_type' => 'strtoupper', 'period' => 'strtolower'] as $field => $normalize) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = $normalize($input[$field]);
            }
        }
        foreach (['all', 'snapshots', 'autoprint'] as $field) {
            if (isset($input[$field]) && is_scalar($input[$field])) {
                $boolean = filter_var($input[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($boolean !== null) {
                    $input[$field] = $boolean;
                }
            }
        }
        $messages = \App\Support\ActivityLogTimeWindow::messages();
        if (($route === 'visitors.index' || $guestReport) && $request->query('record_filter') === 'registered') {
            // Old bookmarks may recover on the list; reports must still reject
            // them rather than silently exporting a broader/different dataset.
            $messages['record_filter.in'] = 'The Registered filter has been removed from Guests. Registered movements remain in Activity Logs.';
        }
        $validator = Validator::make($input, $rules, $messages);
        if ($validator->passes()) {
            return $next($request);
        }
        if ($request->expectsJson() || $request->header('X-Auto-Filter') === '1') {
            return response()->json(['message' => 'Check the selected filters.', 'errors' => $validator->errors()], 422);
        }
        // Remove invalid criteria only, retaining the other inputs. Never
        // redirect a rejected export into a broader automatic download.
        $valid = $validator->valid();
        unset($valid['page']);
        if ($listReport) {
            return response()->view('errors.filters', ['messages' => $validator->errors()->all(),
                'backUrl' => route($registryReport ? 'registry.index' : 'visitors.index', Arr::except($valid, ['snapshots', 'autoprint']))], 422);
        }
        if ($route === 'logs.print' || $route === 'vehicle-events.export.csv'
            || ($route === 'logs.index' && ! in_array($tab, ['scans', 'alerts'], true)
                && array_intersect(array_keys($validator->errors()->toArray()), ['date_from', 'date_to', 'time_from', 'time_to']))) {
            return response()->view('errors.filters', ['messages' => $validator->errors()->all(), 'isList' => $route === 'logs.index',
                'backUrl' => route('logs.index', ['tab' => 'events'] + Arr::except($valid, ['tab', 'report', 'record_type', 'record_id', 'all', 'snapshots', 'autoprint']))], 422);
        }

        return redirect()->to($request->url().($valid ? '?'.Arr::query($valid) : ''))
            ->withErrors($validator)->withInput($valid)->with('invalid_list_filters', true);
    }
}
