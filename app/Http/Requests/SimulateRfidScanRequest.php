<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimulateRfidScanRequest extends FormRequest
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
            'vehicle_rfid_tag_id' => ['nullable', 'integer', 'exists:vehicle_rfid_tags,id', 'required_without:tag_uid'],
            'tag_uid' => ['nullable', 'string', 'max:100', 'required_without:vehicle_rfid_tag_id'],
            'scan_location' => ['required', 'in:entrance,exit'],
            'scan_direction' => ['nullable', 'in:entry,exit'],
            'reader_name' => ['nullable', 'string', 'max:100'],
            'scan_time' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
