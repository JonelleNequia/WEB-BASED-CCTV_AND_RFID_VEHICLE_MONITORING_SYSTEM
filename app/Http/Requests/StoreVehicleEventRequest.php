<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleEventRequest extends FormRequest
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
            'event_type' => ['required', 'in:ENTRY,EXIT'],
            'plate_text' => ['required', 'string', 'max:50'],
            'plate_confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vehicle_type' => ['required', 'string', 'max:50'],
            'vehicle_color' => ['required', 'string', 'max:50'],
            'camera_id' => ['required', 'integer', 'exists:cameras,id'],
            'roi_name' => ['required', 'string', 'max:100'],
            'event_time' => ['required', 'date'],
            'vehicle_image' => ['nullable', 'image', 'max:5120'],
            'plate_image' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
