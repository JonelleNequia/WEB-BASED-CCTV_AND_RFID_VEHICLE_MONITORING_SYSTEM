<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGuestObservationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->hasFile('snapshot_image') && $this->hasFile('snapshot')) {
            $this->files->set('snapshot_image', $this->file('snapshot'));
        }

        if (! $this->filled('plate_number') && $this->filled('plate_text')) {
            $this->merge(['plate_number' => $this->input('plate_text')]);
        }
    }

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
            'plate_number' => ['nullable', 'string', 'max:50'],
            'plate_text' => ['nullable', 'string', 'max:50'],
            'vehicle_type' => ['required', 'string', 'max:50'],
            'vehicle_color' => ['nullable', 'string', 'max:50'],
            'location' => ['required', 'in:entrance,exit'],
            'observation_source' => ['nullable', 'in:manual,cctv'],
            'observed_at' => ['required', 'date'],
            'camera_id' => ['nullable', 'integer', 'exists:cameras,id'],
            'snapshot_image' => ['nullable', 'image', 'max:5120'],
            'snapshot' => ['nullable', 'image', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
