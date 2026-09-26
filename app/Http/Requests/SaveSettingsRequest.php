<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveSettingsRequest extends FormRequest
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
            'matching_threshold_matched' => ['required', 'integer', 'min:1', 'max:200'],
            'matching_threshold_manual_review' => ['required', 'integer', 'min:0', 'max:199', 'lt:matching_threshold_matched'],
            'operating_mode' => ['required', 'in:manual,mock'],
            'deployment_mode' => ['required', 'in:offline_local'],
            'cctv_simulation_mode' => ['required', 'in:enabled,disabled'],
            'rfid_simulation_mode' => ['required', 'in:enabled,disabled'],
            'python_api_key' => ['nullable', 'string', 'max:255'],
            'camera_source_placeholder' => ['nullable', 'string', 'max:255'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'entrance_portal_label' => ['required', 'string', 'max:100'],
            'exit_portal_label' => ['required', 'string', 'max:100'],
            'entrance_rfid_reader_name' => ['required', 'string', 'max:100'],
            'exit_rfid_reader_name' => ['required', 'string', 'max:100'],
            'camera_configs' => ['required', 'array'],
            'camera_configs.entrance.camera_name' => ['required', 'string', 'max:100'],
            'camera_configs.entrance.source_type' => ['required', 'in:webcam,rtsp,url'],
            'camera_configs.entrance.source_value' => ['required', 'string', 'max:500'],
            'camera_configs.entrance.source_username' => ['nullable', 'string', 'max:255'],
            'camera_configs.entrance.source_password' => ['nullable', 'string', 'max:255'],
            'camera_configs.exit.camera_name' => ['required', 'string', 'max:100'],
            'camera_configs.exit.source_type' => ['required', 'in:webcam,rtsp,url'],
            'camera_configs.exit.source_value' => ['required', 'string', 'max:500'],
            'camera_configs.exit.source_username' => ['nullable', 'string', 'max:255'],
            'camera_configs.exit.source_password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Trim nested camera fields before validating cross-field source rules.
     */
    protected function prepareForValidation(): void
    {
        $cameraConfigs = $this->input('camera_configs', []);

        if (! is_array($cameraConfigs)) {
            return;
        }

        foreach (['entrance', 'exit'] as $role) {
            if (! isset($cameraConfigs[$role]) || ! is_array($cameraConfigs[$role])) {
                continue;
            }

            foreach (['camera_name', 'source_type', 'source_value', 'source_username', 'source_password'] as $field) {
                if (array_key_exists($field, $cameraConfigs[$role])) {
                    $cameraConfigs[$role][$field] = trim((string) $cameraConfigs[$role][$field]);
                }
            }
        }

        $this->merge([
            'camera_configs' => $cameraConfigs,
        ]);
    }

    /**
     * Validate that the selected source type matches the entered source value.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['entrance' => 'Entrance', 'exit' => 'Exit'] as $role => $label) {
                $sourceType = (string) $this->input("camera_configs.$role.source_type", '');
                $sourceValue = trim((string) $this->input("camera_configs.$role.source_value", ''));
                $field = "camera_configs.$role.source_value";

                if ($sourceType === 'webcam') {
                    if (! ctype_digit($sourceValue) || (int) $sourceValue < 0) {
                        $validator->errors()->add($field, "$label webcam source must be a camera number like 0 or 1.");
                    }

                    continue;
                }

                if ($sourceType === 'rtsp') {
                    $parsed = parse_url($sourceValue);

                    if (strtolower((string) ($parsed['scheme'] ?? '')) !== 'rtsp' || empty($parsed['host'])) {
                        $validator->errors()->add($field, "$label RTSP source must be a full URL like rtsp://192.168.1.50:554/stream1. Do not use 0 for RTSP.");
                    }

                    continue;
                }

                if ($sourceType === 'url' && filter_var($sourceValue, FILTER_VALIDATE_URL) === false) {
                    $validator->errors()->add($field, "$label URL source must be a full camera stream URL.");
                }
            }
        });
    }
}
