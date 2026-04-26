<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreVehicleRegistrationRequest extends FormRequest
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
            'plate_number' => ['required', 'string', 'max:50'],
            'owner_name' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:parent,student,faculty_staff,guard,guest'],
            'vehicle_type' => ['required', 'string', 'max:50'],
            'vehicle_color' => ['required', 'string', 'max:50'],
            'status' => ['required', 'in:active,inactive'],
            'tag_uid' => ['nullable', 'string', 'max:100'],
            'tag_label' => ['nullable', 'string', 'max:100'],
            'tag_status' => ['nullable', 'in:active,inactive'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Apply business rules for recurring RFID registration.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                $this->input('category') === 'guest'
                && filled($this->input('tag_uid'))
            ) {
                $validator->errors()->add(
                    'tag_uid',
                    'Guest vehicles should be monitored through CCTV/manual guest logging and should not be assigned an RFID tag.'
                );
            }
        });
    }

    /**
     * Set safe defaults for backward compatibility and seeded form submissions.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('category')) {
            $this->merge(['category' => 'faculty_staff']);
        }
    }
}
