<?php

namespace App\Http\Requests;

use App\Models\RfidTag;
use App\Models\Vehicle;
use App\Rules\PersonalName;
use App\Rules\PlateIdentifier;
use App\Rules\RegistrationStaffName;
use App\Rules\RegistrationOwnerName;
use App\Support\VehicleCategory;
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
        $vehicle = $this->route('vehicle');
        $legacyOwner = $vehicle instanceof Vehicle ? $vehicle->vehicle_owner_name : null;
        $legacyPlate = $vehicle instanceof Vehicle ? $vehicle->plate_number : null;
        $blankFields = $this->attributes->get('blank_name_fields', []);
        $blankOwner = in_array('vehicle_owner_name', $blankFields, true)
            || ($this->input('vehicle_owner_name') === null && in_array('owner_name', $blankFields, true));
        $invalidOwnerFields = $this->attributes->get('invalid_owner_name_fields', []);
        $invalidNewOwner = in_array('vehicle_owner_name', $invalidOwnerFields, true)
            || ($this->attributes->get('registration_owner_from_alias') && in_array('owner_name', $invalidOwnerFields, true));

        return [
            // Registration attribution is recorded once; unrelated edits leave
            // both it and the installation's new-registration default alone.
            ...($vehicle instanceof Vehicle ? [] : ['registered_by' => RegistrationStaffName::rules()]),
            'rfid_tag_id' => [
                'nullable',
                'integer',
                'exists:vehicle_rfid_tags,id',
            ],
            'rfid_uid' => [
                'nullable',
                'string',
                'max:100',
            ],
            'rfid_scan_uid' => ['nullable', 'string', 'max:100'],
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
                new PlateIdentifier(legacyValue: $legacyPlate),
                Rule::unique('vehicles', 'plate_number')->ignore($vehicleId),
            ],
            'vehicle_owner_name' => $vehicle instanceof Vehicle ? ['bail', 'nullable', 'string', new PersonalName($legacyOwner, $blankOwner), Rule::when(
                $legacyOwner === null || ! is_string($this->input('vehicle_owner_name')) || PersonalName::normalize($this->input('vehicle_owner_name')) !== PersonalName::normalize($legacyOwner),
                'max:100'
            )] : RegistrationOwnerName::rules($invalidNewOwner),
            // Phase 4 (visitor model): Faculty & Staff or Registered Guest.
            // Unregistered Visitor is set by the system (camera, no tag).
            'category' => [
                'bail', 'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $value = strtolower(trim((string) $value));

                    if (in_array($value, [VehicleCategory::UNREGISTERED_VISITOR, 'guest', 'guests'], true)) {
                        $fail('Unregistered Guest cannot be picked here: the system sets it for vehicles the camera sees without a registered tag. Choose Faculty & Staff or Registered Guest.');
                    } elseif (! in_array($value, VehicleCategory::REGISTRY, true)) {
                        $fail('Choose Faculty & Staff or Registered Guest.');
                    }
                },
            ],
            'vehicle_type' => ['required', 'string', new \App\Rules\ValidVehicleType($vehicle instanceof Vehicle ? $vehicle->vehicle_type : null),
                Rule::when(! $vehicle instanceof Vehicle || $this->input('vehicle_type') !== $vehicle->vehicle_type, 'max:50')],
            // Phase 6 (visitor model): "Register this vehicle" from the Visitor Ranking.
            'plate_profile_id' => ['nullable', 'integer', 'exists:plate_profiles,id'],
            'vehicle_type_other' => ['nullable', 'required_if:vehicle_type,Others', 'string',
                Rule::when(! $vehicle instanceof Vehicle || $this->input('vehicle_type_other') !== $vehicle->vehicle_type, 'max:50')],
            // UI Phase 3: Add Vehicle drawer registers a new scanned tag automatically.
            'auto_register_tag' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...RegistrationStaffName::messages(),
            ...RegistrationOwnerName::messages(),
            // UI Phase 3: clear message for the Add Vehicle / Edit drawers.
            'rfid_tag_uid.unique' => 'This RFID tag is already assigned to another vehicle. Use Replace Tag on that vehicle first.',
        ];
    }

    /**
     * Set safe defaults for backward compatibility and seeded form submissions.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('rfid_uid') && ! $this->filled('rfid_tag_id') && is_string($this->input('rfid_scan_uid'))) {
            $this->merge(['rfid_uid' => $this->input('rfid_scan_uid')]);
        }
        if (is_string($this->input('registered_by'))) {
            $this->merge(['registered_by' => RegistrationStaffName::normalize($this->input('registered_by'))]);
        }

        if (in_array($this->input('vehicle_owner_name'), [null, ''], true) && $this->filled('owner_name')) {
            $this->attributes->set('registration_owner_from_alias', true);
            $this->merge(['vehicle_owner_name' => $this->input('owner_name')]);
        }

        if (in_array($this->input('rfid_tag_uid'), [null, ''], true) && $this->filled('tag_uid')) {
            $this->merge(['rfid_tag_uid' => $this->input('tag_uid')]);
        }

        if (in_array($this->input('rfid_tag_uid'), [null, ''], true) && $this->filled('rfid_uid')) {
            $this->merge(['rfid_tag_uid' => $this->input('rfid_uid')]);
        }

        if (is_string($this->input('rfid_tag_uid')) && $this->filled('rfid_tag_uid')) {
            $normalizedUid = RfidTag::normalizeUid((string) $this->input('rfid_tag_uid'));

            $this->merge([
                'rfid_uid' => $normalizedUid,
                'rfid_tag_uid' => $normalizedUid,
            ]);
        }

        if (is_string($this->input('plate_number')) && $this->filled('plate_number')) {
            $this->merge([
                'plate_number' => \App\Support\PlateNumber::normalize($this->input('plate_number')),
            ]);
        }

        if (is_string($this->input('vehicle_owner_name')) && $this->filled('vehicle_owner_name')) {
            $normalizedOwner = PersonalName::normalize($this->input('vehicle_owner_name'));

            $this->merge([
                'vehicle_owner_name' => trim((string) $normalizedOwner),
            ]);
        }

        if (in_array($this->input('category'), [null, ''], true)) {
            $this->merge(['category' => VehicleCategory::FACULTY_STAFF]);
        }

        // Phase 4: old forms and clients may still send Parent / Student / Guard.
        if (is_string($this->input('category')) && in_array(strtolower($this->input('category')), VehicleCategory::LEGACY_REGISTERED, true)) {
            $this->merge(['category' => VehicleCategory::REGISTERED_VISITOR]);
        }

        if ($this->input('vehicle_type') === 'Others' && is_string($this->input('vehicle_type_other')) && $this->filled('vehicle_type_other')) {
            $this->merge([
                'vehicle_type' => \App\Support\VehicleType::canonical($this->input('vehicle_type_other')) ?? trim($this->input('vehicle_type_other')),
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
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
                && (int) $tag->id === (int) $vehicle->rfid_tag_id;

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
