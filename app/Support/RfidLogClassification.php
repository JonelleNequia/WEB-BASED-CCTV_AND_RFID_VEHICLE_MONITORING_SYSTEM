<?php

namespace App\Support;

use App\Models\RfidScanLog;

final class RfidLogClassification
{
    /**
     * Classify the recorded scan, never plate text, UID shape or client metadata.
     *
     * @return array{log_type: string, log_type_label: string, is_guest: bool, category_label: string}
     */
    public static function fields(RfidScanLog $scan): array
    {
        $unknown = $scan->isUnknownTag() || $scan->verification_status === 'unassigned_tag';
        $oldPass = $scan->vehicle_category === 'guest_pass';
        $oldVisitor = ! $unknown && ($scan->verification_status === 'guest'
            || VehicleCategory::normalize($scan->vehicle_category) === VehicleCategory::UNREGISTERED_VISITOR);
        $registered = $scan->hasRecordedRegistration();
        $type = match (true) {
            $oldPass => 'guest_pass',
            $oldVisitor => 'no_pass_alert',
            $registered => 'registered',
            default => 'rfid_scan',
        };

        return [
            'log_type' => $type,
            'log_type_label' => match ($type) {
                'guest_pass' => 'Old pass record',
                'no_pass_alert' => 'Unregistered Visitor (camera)',
                'registered' => 'Registered',
                default => 'RFID Scan',
            },
            'is_guest' => $oldPass || $oldVisitor,
            'category_label' => match ($type) {
                'guest_pass' => VehicleCategory::label('guest_pass'),
                'no_pass_alert' => VehicleCategory::label(VehicleCategory::UNREGISTERED_VISITOR),
                'registered' => VehicleCategory::label($scan->vehicle_category ?: $scan->vehicle?->category),
                default => 'N/A',
            },
        ];
    }
}
