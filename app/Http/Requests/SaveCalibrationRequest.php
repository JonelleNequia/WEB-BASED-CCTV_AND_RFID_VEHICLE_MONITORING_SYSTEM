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
            'browser_device_id' => ['nullable', 'string', 'max:255'],
            'browser_label' => ['nullable', 'string', 'max:255'],
            'last_connection_status' => ['nullable', 'in:connected,not_connected,denied,unavailable,error'],
            'last_connection_message' => ['nullable', 'string', 'max:1000'],
            'calibration_mask' => ['nullable', 'array'],
            'calibration_mask.x' => ['required_with:calibration_mask', 'numeric', 'between:0,1'],
            'calibration_mask.y' => ['required_with:calibration_mask', 'numeric', 'between:0,1'],
            'calibration_mask.width' => ['required_with:calibration_mask', 'numeric', 'between:0,1'],
            'calibration_mask.height' => ['required_with:calibration_mask', 'numeric', 'between:0,1'],
            'calibration_line' => ['nullable', 'array'],
            'calibration_line.x1' => ['required_with:calibration_line', 'numeric', 'between:0,1'],
            'calibration_line.y1' => ['required_with:calibration_line', 'numeric', 'between:0,1'],
            'calibration_line.x2' => ['required_with:calibration_line', 'numeric', 'between:0,1'],
            'calibration_line.y2' => ['required_with:calibration_line', 'numeric', 'between:0,1'],
        ];
    }
}
