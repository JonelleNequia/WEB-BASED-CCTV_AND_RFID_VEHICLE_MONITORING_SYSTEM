<?php

namespace Tests\fixtures;

use App\Models\User;
use App\Models\VisitorRecord;
use App\Services\VisitorRecordService;

/** Explicit staff decision for fixtures whose subject is a confirmed actual plate. */
final class ConfirmedActualPlate
{
    public static function for(VisitorRecord $record, ?string $plate = null): VisitorRecord
    {
        return app(VisitorRecordService::class)->correctPlate($record, $plate ?? $record->ocr_plate_number,
            User::where('role', 'admin')->firstOrFail());
    }
}
