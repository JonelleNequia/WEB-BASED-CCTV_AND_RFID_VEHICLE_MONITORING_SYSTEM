<?php

namespace App\Http\Requests;

use App\Models\RfidTag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        $vehicleId = $this->route('vehicle')?->id;

        return [
            'rfid_tag_id' => [
                'nullable',
                'required_without:rfid_tag_uid',
                'integer',
                'exists:vehicle_rfid_tags,id',
            ],
            'rfid_tag_uid' => [
                'nullable',
                'required_without:rfid_tag_id',
                'string',
                'max:100',
                Rule::unique('vehicles', 'rfid_tag_uid')->ignore($vehicleId),
            ],
            'plate_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('vehicles', 'plate_number')->ignore($vehicleId),
            ],
            'vehicle_owner_name' => ['nullable', 'string', 'max:100'],
            'category' => ['required', 'in:parent,student,faculty_staff,guard'],
            'vehicle_type' => ['required', 'in:Car,Motorcycle,Bus'],
        ];
    }

    /**
     * Set safe defaults for backward compatibility and seeded form submissions.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('vehicle_owner_name') && $this->filled('owner_name')) {
            $this->merge(['vehicle_owner_name' => $this->input('owner_name')]);
        }

        if (! $this->filled('rfid_tag_uid') && $this->filled('tag_uid')) {
            $this->merge(['rfid_tag_uid' => $this->input('tag_uid')]);
        }

        if ($this->filled('rfid_tag_uid')) {
            $this->merge([
                'rfid_tag_uid' => RfidTag::normalizeUid((string) $this->input('rfid_tag_uid')),
            ]);
        }

        if ($this->filled('plate_number')) {
            $normalizedPlate = preg_replace('/\s+/', ' ', (string) $this->input('plate_number'));
            $this->merge([
                'plate_number' => strtoupper(trim((string) $normalizedPlate)),
            ]);
        }

        if (! $this->filled('category')) {
            $this->merge(['category' => 'faculty_staff']);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('rfid_tag_id')) {
                return;
            }

            $tag = RfidTag::query()->find((int) $this->input('rfid_tag_id'));

            if (! $tag) {
                return;
            }

            $vehicle = $this->route('vehicle');
            $belongsToCurrentVehicle = $vehicle
                && (int) $tag->vehicle_id === (int) $vehicle->id
                && $tag->status === RfidTag::STATUS_ASSIGNED;

            if ($tag->status !== RfidTag::STATUS_AVAILABLE && ! $belongsToCurrentVehicle) {
                $validator->errors()->add('rfid_tag_id', 'The selected RFID tag is not available.');
            }
        });
    }
}
