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
            'calibration_line' => ['nullable', 'array'],
            'calibration_line.x1' => ['required_with:calibration_line', 'numeric', 'between:0,1'],
            'calibration_line.y1' => ['required_with:calibration_line', 'numeric', 'between:0,1'],
            'calibration_line.x2' => ['required_with:calibration_line', 'numeric', 'between:0,1'],
            'calibration_line.y2' => ['required_with:calibration_line', 'numeric', 'between:0,1'],
            // Phase 2: the side of the line a vehicle moves to when it goes IN.
            'calibration_line.in_side' => ['nullable', 'integer', 'in:-1,1'],
        ];
    }
}
