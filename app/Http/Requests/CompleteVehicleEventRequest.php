<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteVehicleEventRequest extends FormRequest
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
            'plate_text' => ['required', 'string', 'max:50'],
            'plate_confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vehicle_type' => ['required', 'string', 'max:50'],
            'vehicle_color' => ['required', 'string', 'max:50'],
            'plate_image' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
