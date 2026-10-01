<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesIntegration;
use App\Http\Controllers\Controller;
use App\Rules\ValidGate;
use App\Services\SettingsService;
use App\Services\VisitorRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5 (visitor model): the detector's plate vote for a vehicle that
 * crossed with no registered tag (plate, or "unreadable" with a best guess),
 * with the plate image.
 */
class VisitorIntegrationController extends Controller
{
    use AuthorizesIntegration;

    public function storePlate(Request $request, SettingsService $settingsService, VisitorRecordService $visitorRecordService): JsonResponse
    {
        if ($denied = $this->authorizeIntegrationRequest($request, $settingsService)) {
            return $denied;
        }

        // Multipart sends the vote details as a JSON string.
        if (is_string($request->input('ocr_details'))) {
            $request->merge(['ocr_details' => json_decode($request->input('ocr_details'), true) ?: []]);
        }

        $validated = $request->validate([
            'external_event_key' => ['required', 'string', 'max:120'],
            'camera_role' => ['required', 'string', new ValidGate],
            'event_time' => ['required', 'date'],
            'plate_status' => ['required', 'in:read,unreadable'],
            'plate_number' => ['nullable', 'required_if:plate_status,read', 'string', 'max:30'],
            'plate_confidence' => ['nullable', 'numeric', 'between:0,1'],
            'best_guess' => ['nullable', 'string', 'max:30'],
            'vehicle_color' => ['nullable', 'string', 'max:30'],
            'detected_vehicle_type' => ['nullable', 'string', 'max:50'],
            'ocr_details' => ['nullable', 'array'],
            'plate_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $record = $visitorRecordService->applyPlateReading($validated, $request->file('plate_image'));

        return response()->json([
            'message' => 'Visitor plate stored.',
            'visitor_record' => [
                'id' => $record->id,
                'status' => $record->status,
                'plate_status' => $record->plate_status,
                'plate_number' => $record->plate_number,
                'plate_profile_id' => $record->plate_profile_id,
            ],
        ]);
    }
}
