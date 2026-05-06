<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGuestObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plate_number' => ['nullable', 'string', 'max:50'],
            'vehicle_type' => ['nullable', 'string', 'max:50'],
            'vehicle_color' => ['nullable', 'string', 'max:50'],
            'location' => ['required', 'in:entrance,exit'],
            'observed_at' => ['required', 'date'],
            'status' => ['required', 'in:pending_review,reviewed,verified'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
