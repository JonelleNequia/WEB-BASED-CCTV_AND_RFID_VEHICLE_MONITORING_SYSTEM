<?php

namespace App\Http\Requests;

use App\Rules\ValidGate;
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
            'plate_number' => ['nullable', 'string', 'max:50', new \App\Rules\PlateIdentifier(20, $this->route('guestVehicleObservation')?->plate_number)],
            'vehicle_type' => ['nullable', 'string', 'max:50', new \App\Rules\ValidVehicleType($this->route('guestVehicleObservation')?->vehicle_type)],
            'location' => ['required', new ValidGate(original: $this->route('guestVehicleObservation')?->location)],
            'observed_at' => ['required', 'date'],
            'status' => ['required', 'in:pending_review,reviewed,verified'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
