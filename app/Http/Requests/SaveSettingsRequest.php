<?php

namespace App\Http\Requests;

use App\Models\Gate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        $rules = $this->allRules();
        $section = (string) $this->input('section', '');

        // UI Phase 2: each Settings tab posts only its own fields.
        if (! array_key_exists($section, self::SECTIONS)) {
            return $rules;
        }

        return array_filter(
            $rules,
            fn (string $field): bool => collect(self::SECTIONS[$section])
                ->contains(fn (string $prefix): bool => $field === $prefix || str_starts_with($field, $prefix.'.')),
            ARRAY_FILTER_USE_KEY
        ) + ['section' => ['required', 'string']];
    }

    /** UI Phase 2: which fields each Settings tab saves. */
    public const SECTIONS = [
        // B1 (Settings): General (gate names), Advanced › Timing, Detection, Manual setup.
        'general' => ['gates'],
        'timing' => ['rfid_cooldown_seconds', 'rfid_lookback_seconds', 'rfid_lookahead_seconds'],
        'detection' => [
            'perf_stream_fps', 'perf_stream_width', 'perf_jpeg_quality', 'perf_detection_fps',
            'perf_yolo_imgsz', 'perf_yolo_device', 'perf_roi_crop', 'perf_hires_on_trigger',
            'perf_type_second_pass', 'perf_type_model', 'perf_type_truck_min_height', 'perf_type_car_min_aspect',
            'perf_cross_margin', 'perf_cross_min_points', 'perf_cross_min_move',
        ],
        'manual' => ['camera_configs', 'camera_source_placeholder', 'camera_streams', 'gates'],
        // Phase 1: Gates & Readers (name, reader type, manual reader address per gate).
        'stations' => [
            'gates',
            'rfid_cooldown_seconds',
            'rfid_lookback_seconds',
            'rfid_lookahead_seconds',
        ],
        'cameras' => [
            'camera_configs', 'camera_source_placeholder', 'camera_streams',
            'perf_stream_fps', 'perf_stream_width', 'perf_jpeg_quality', 'perf_detection_fps',
            'perf_yolo_imgsz', 'perf_yolo_device', 'perf_roi_crop', 'perf_hires_on_trigger',
            'perf_type_second_pass', 'perf_type_model', 'perf_type_truck_min_height', 'perf_type_car_min_aspect',
            'perf_cross_margin', 'perf_cross_min_points', 'perf_cross_min_move',
        ],
    ];

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    protected function allRules(): array
    {
        return [
            'matching_threshold_matched' => ['required', 'integer', 'min:1', 'max:200'],
            'matching_threshold_manual_review' => ['required', 'integer', 'min:0', 'max:199', 'lt:matching_threshold_matched'],
            'operating_mode' => ['required', 'in:manual,mock'],
            'deployment_mode' => ['required', 'in:offline_local'],
            'cctv_simulation_mode' => ['required', 'in:enabled,disabled'],
            'rfid_simulation_mode' => ['required', 'in:enabled,disabled'],
            'camera_source_placeholder' => ['nullable', 'string', 'max:255'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            // Phase 1: one entry per gate, keyed by gate code. The reader is
            // picked in Devices; the manual address (a local address only) is
            // an override.
            'gates' => ['sometimes', 'array'],
            'gates.*.name' => ['required', 'string', 'max:100'],
            'gates.*.reader_type' => ['sometimes', Rule::in(array_keys(Gate::READER_TYPES))],
            'gates.*.reader_manual' => ['sometimes', 'in:0,1'],
            'gates.*.reader_transport' => ['sometimes', 'in:tcp,udp'],
            'gates.*.reader_ip' => ['nullable', 'required_if:gates.*.reader_manual,1', 'ipv4', $this->localAddressRule()],
            'gates.*.reader_port' => ['nullable', 'required_if:gates.*.reader_manual,1', 'integer', 'between:1,65535'],
            'gates.*.is_active' => ['sometimes', 'in:0,1'],
            // Phase 3: at least 10 s (0 recorded every read of a tag again).
            'rfid_cooldown_seconds' => ['sometimes', 'integer', 'min:10', 'max:3600'],
            'rfid_lookback_seconds' => ['sometimes', 'integer', 'min:1', 'max:15'],
            'rfid_lookahead_seconds' => ['sometimes', 'integer', 'min:1', 'max:10'],
            // Cameras tab: one camera per gate (keys = gate codes).
            'camera_configs' => ['required', 'array'],
            'camera_configs.*.camera_name' => ['required', 'string', 'max:100'],
            'camera_configs.*.source_type' => ['required', 'in:webcam,rtsp,url,none'],
            'camera_configs.*.source_value' => ['nullable', 'required_unless:camera_configs.*.source_type,none', 'string', 'max:500'],
            'camera_configs.*.source_username' => ['nullable', 'string', 'max:255'],
            'camera_configs.*.source_password' => ['nullable', 'string', 'max:255'],
            'camera_configs.*.clear_password' => ['nullable', 'boolean'],
            'camera_configs.*.snapshot_source_value' => ['nullable', 'string', 'max:500'],
            // Live-latency work: stream roles for assigned cameras and tuning.
            'camera_streams' => ['sometimes', 'array'],
            'camera_streams.*.stream' => ['nullable', 'in:main,sub'],
            'camera_streams.*.snapshots' => ['nullable', 'in:0,1'],
            'perf_stream_fps' => ['sometimes', 'numeric', 'between:1,30'],
            'perf_stream_width' => ['sometimes', 'integer', 'between:320,1920'],
            'perf_jpeg_quality' => ['sometimes', 'integer', 'between:30,95'],
            'perf_detection_fps' => ['sometimes', 'numeric', 'between:1,25'],
            'perf_yolo_imgsz' => ['sometimes', 'integer', 'in:320,384,416,480,512,640'],
            'perf_yolo_device' => ['sometimes', 'in:auto,cpu,mps,cuda:0'],
            'perf_roi_crop' => ['sometimes', 'in:0,1'],
            'perf_hires_on_trigger' => ['sometimes', 'in:0,1'],
            // A2 (detection): vehicle type.
            'perf_type_second_pass' => ['sometimes', 'in:0,1'],
            'perf_type_model' => ['sometimes', 'in:yolov8n.pt,yolov8s.pt'],
            'perf_type_truck_min_height' => ['sometimes', 'integer', 'between:20,95'],
            'perf_type_car_min_aspect' => ['sometimes', 'numeric', 'between:0.8,2.5'],
            // A3 (detection): one vehicle = one event.
            'perf_cross_margin' => ['sometimes', 'integer', 'between:0,30'],
            'perf_cross_min_points' => ['sometimes', 'integer', 'between:1,10'],
            'perf_cross_min_move' => ['sometimes', 'integer', 'between:0,60'],
        ];
    }

    /**
     * A reader address must be on a local network (private or link-local),
     * never a public internet address typed by mistake.
     */
    protected function localAddressRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            // Private, link-local and loopback ranges fail this filter; public ones pass.
            $isPublic = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

            if ($isPublic) {
                $fail('This is not a local network address. Check the reader IP, or pick the reader in Devices instead.');
            }
        };
    }

    /**
     * Phase 1: forms and clients from before gates sent entrance_* / exit_*
     * fields; they mean Gate 1 / Gate 2.
     */
    protected function mergeLegacyGateFields(): void
    {
        $gates = (array) $this->input('gates', []);
        $map = [
            'portal_label' => 'name', 'reader_type' => 'reader_type', 'rfid_reader_name' => 'reader_name',
            'reader_manual' => 'reader_manual', 'reader_ip' => 'reader_ip', 'reader_port' => 'reader_port',
            'reader_transport' => 'reader_transport',
        ];

        foreach (Gate::LEGACY_CODES as $station => $code) {
            foreach ($map as $old => $new) {
                if ($this->has("{$station}_{$old}") && ! isset($gates[$code][$new])) {
                    $gates[$code][$new] = $this->input("{$station}_{$old}");
                }
            }
        }

        if ($gates !== []) {
            // A legacy form without labels keeps the current names.
            foreach ($gates as $code => $values) {
                if (! array_key_exists('name', (array) $values)) {
                    $gates[$code]['name'] = Gate::query()->where('code', $code)->value('name') ?? '';
                }
                // Phase 3: NFC was dropped; a gate is a UHF gate.
                if (($values['reader_type'] ?? null) === 'nfc') {
                    $gates[$code]['reader_type'] = 'uhf_ethernet';
                }
            }
            $this->merge(['gates' => $gates]);
        }
    }

    /**
     * @return mixed
     */
    protected function legacyKeysToGateCodes(mixed $values): mixed
    {
        if (! is_array($values)) {
            return $values;
        }

        $mapped = [];
        foreach ($values as $key => $value) {
            $mapped[Gate::LEGACY_CODES[$key] ?? $key] = $value;
        }

        return $mapped;
    }

    /**
     * Trim nested camera fields before validating cross-field source rules.
     */
    protected function prepareForValidation(): void
    {
        $this->mergeLegacyGateFields();

        $cameraConfigs = $this->legacyKeysToGateCodes($this->input('camera_configs', []));

        if ($this->has('camera_streams')) {
            $this->merge(['camera_streams' => $this->legacyKeysToGateCodes($this->input('camera_streams', []))]);
        }

        if (! is_array($cameraConfigs)) {
            return;
        }

        foreach (array_keys($cameraConfigs) as $role) {
            if (! is_array($cameraConfigs[$role])) {
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
            $gates = (array) $this->input('gates', []);
            if ($gates !== [] && collect($gates)->every(fn ($gate): bool => (string) ($gate['is_active'] ?? '1') === '0')) {
                $validator->errors()->add('gates', 'Keep at least one gate active.');
            }

            $configs = (array) $this->input('camera_configs', []);
            foreach (array_keys($configs) as $role) {
                $label = Gate::labelFor((string) $role);
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
                        $validator->errors()->add($field, "$label RTSP source must be a full rtsp:// address with the camera host and stream path. Do not use 0 for RTSP.");
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
