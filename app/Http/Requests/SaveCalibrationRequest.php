<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveCalibrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'camera_id' => ['required', 'integer', 'exists:cameras,id'],
            'calibration_mask' => ['nullable', 'array', 'min:3'],
            'calibration_mask.*.x' => ['required_with:calibration_mask', 'numeric', 'between:0,1'],
            'calibration_mask.*.y' => ['required_with:calibration_mask', 'numeric', 'between:0,1'],
            // ROI work: only the zone is drawn; the gate says whether a vehicle
            // moving away from the camera is IN or OUT (empty = not set).
            'away_direction' => ['sometimes', 'nullable', 'in:IN,OUT'],
        ];
    }
}
