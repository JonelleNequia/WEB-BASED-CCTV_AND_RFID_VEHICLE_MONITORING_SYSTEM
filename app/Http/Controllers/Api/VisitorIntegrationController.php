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
            $details = json_decode($request->input('ocr_details'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($details)) {
                $request->merge(['ocr_details' => $details]);
            }
        }

        $validated = $request->validate([
            'external_event_key' => ['required', 'string', 'max:120'],
            'camera_role' => ['required', 'string', new ValidGate],
            'camera_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('cameras', 'id'), new \App\Rules\CameraAtGate('camera_role')],
            'event_time' => ['required', 'date'],
            'plate_status' => ['required', 'in:read,unreadable'],
            // Invalid OCR is evidence to review, not a sanitized registered key.
            'plate_number' => ['nullable', 'string', 'max:30'],
            'plate_confidence' => ['nullable', 'numeric', 'between:0,1'],
            'best_guess' => ['nullable', 'string', 'max:30'],
            'detected_vehicle_type' => ['nullable', 'string', 'max:50'],
            'ocr_details' => ['nullable', 'array'],
            'plate_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120', 'dimensions:max_width=8192,max_height=8192'],
            'plate_image_path' => ['prohibited'],
            'snapshot_path' => ['prohibited'],
        ]);

        try {
            $record = $visitorRecordService->applyPlateReading($validated, $request->file('plate_image'));
        } catch (\App\Exceptions\OccurrenceConflict $exception) {
            return response()->json(['message' => 'Occurrence context conflicts with existing evidence.'], 409);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Plate evidence could not be saved. Retry this occurrence.'], 500);
        }

        return response()->json([
            'message' => 'Guest plate stored.',
            'visitor_record' => [
                'id' => $record->id,
                'status' => $record->status,
                'plate_status' => $record->plate_status,
                'plate_number' => $record->plate_number,
                'plate_profile_id' => $record->plate_profile_id,
                'registration_status' => $record->registration_status,
                'registration_reason' => $record->registration_reason,
                'vehicle_id' => $record->vehicle_id,
                'vehicle_event_id' => $record->movementEvent?->id,
                'vehicle_type' => $record->display_vehicle_type,
                'vehicle_type_source' => $record->vehicle_type_source_label,
                'detected_vehicle_type' => data_get($record->ocr_details_json, \App\Services\VehicleTypeEvidenceService::KEY.'.raw'),
                'snapshot_url' => $record->snapshot_url,
                'plate_image_url' => $record->plate_image_url,
            ],
        ]);
    }
}
