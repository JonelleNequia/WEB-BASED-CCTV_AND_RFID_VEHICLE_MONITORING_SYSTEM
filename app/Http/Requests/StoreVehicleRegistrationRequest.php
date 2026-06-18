<?php

namespace App\Http\Requests;

use App\Models\RfidTag;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
        $vehicleId = $this->routeVehicleId();

        return [
            'rfid_tag_id' => [
                'nullable',
                'required_without_all:rfid_uid,rfid_tag_uid',
                'integer',
                'exists:vehicle_rfid_tags,id',
            ],
            'rfid_uid' => [
                'nullable',
                'string',
                'max:100',
            ],
            'rfid_tag_uid' => [
                'nullable',
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
            'category' => ['required', 'string', 'max:50'],
            'category_other' => ['nullable', 'required_if:category,others', 'string', 'max:50'],
            'vehicle_type' => ['required', 'string', 'max:50'],
            'vehicle_type_other' => ['nullable', 'required_if:vehicle_type,Others', 'string', 'max:50'],
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

        if (! $this->filled('rfid_tag_uid') && $this->filled('rfid_uid')) {
            $this->merge(['rfid_tag_uid' => $this->input('rfid_uid')]);
        }

        if ($this->filled('rfid_tag_uid')) {
            $normalizedUid = RfidTag::normalizeUid((string) $this->input('rfid_tag_uid'));

            $this->merge([
                'rfid_uid' => $normalizedUid,
                'rfid_tag_uid' => $normalizedUid,
            ]);
        }

        if ($this->filled('plate_number')) {
            $normalizedPlate = preg_replace('/\s+/', ' ', (string) $this->input('plate_number'));
            $this->merge([
                'plate_number' => strtoupper(trim((string) $normalizedPlate)),
            ]);
        }

        if ($this->filled('vehicle_owner_name')) {
            $normalizedOwner = preg_replace('/\s+/', ' ', (string) $this->input('vehicle_owner_name'));

            $this->merge([
                'vehicle_owner_name' => trim((string) $normalizedOwner),
            ]);
        }

        if (! $this->filled('category')) {
            $this->merge(['category' => 'faculty_staff']);
        }

        if ($this->input('category') === 'others' && $this->filled('category_other')) {
            $this->merge([
                'category' => trim((string) $this->input('category_other')),
            ]);
        }

        if ($this->input('vehicle_type') === 'Others' && $this->filled('vehicle_type_other')) {
            $this->merge([
                'vehicle_type' => Str::title(trim((string) $this->input('vehicle_type_other'))),
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateUniqueOwnerPlatePair($validator);

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

    protected function validateUniqueOwnerPlatePair(Validator $validator): void
    {
        if (! $this->filled('plate_number') || ! $this->filled('vehicle_owner_name')) {
            return;
        }

        $plateFingerprint = $this->plateFingerprint((string) $this->input('plate_number'));
        $ownerFingerprint = $this->ownerFingerprint((string) $this->input('vehicle_owner_name'));

        if ($plateFingerprint === '' || $ownerFingerprint === '') {
            return;
        }

        $vehicleId = $this->routeVehicleId();
        $duplicate = Vehicle::query()
            ->when($vehicleId, fn ($query) => $query->whereKeyNot($vehicleId))
            ->get(['id', 'plate_number', 'vehicle_owner_name'])
            ->first(fn (Vehicle $vehicle): bool => $this->plateFingerprint((string) $vehicle->plate_number) === $plateFingerprint
                && $this->ownerFingerprint((string) $vehicle->vehicle_owner_name) === $ownerFingerprint);

        if ($duplicate) {
            $validator->errors()->add(
                'plate_number',
                'This plate number is already registered for the same vehicle owner.'
            );
        }
    }

    protected function routeVehicleId(): mixed
    {
        $routeVehicle = $this->route('vehicle');

        return $routeVehicle instanceof Vehicle
            ? $routeVehicle->getKey()
            : $routeVehicle;
    }

    protected function plateFingerprint(string $plateNumber): string
    {
        return preg_replace('/[^A-Z0-9]/', '', Str::upper($plateNumber)) ?? '';
    }

    protected function ownerFingerprint(string $ownerName): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($ownerName)) ?? $ownerName;

        return Str::lower($normalized);
    }
}
