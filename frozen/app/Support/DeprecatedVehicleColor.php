<?php

namespace App\Support;

class DeprecatedVehicleColor
{
    /** Remove retired fields from new input, including older detector metadata. */
    public static function without(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['vehicle_color', 'detected_vehicle_color', 'color_analysis', 'color_error'], true)) {
                unset($data[$key]);

                continue;
            }
            if (is_string($value) && in_array($key, ['detection_metadata', 'detection_metadata_json', 'ocr_details', 'ocr_details_json'], true)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }
            }
            if (is_array($value)) {
                $data[$key] = self::without($value);
            }
        }

        return $data;
    }
}
