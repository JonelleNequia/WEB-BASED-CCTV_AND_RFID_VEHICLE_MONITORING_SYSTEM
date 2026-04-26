<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
}
