<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ROI work, Phase 2: a "Direction unknown" record set to IN or OUT by staff
 * (who, when, and what the camera had said). Also the measure of how often
 * the camera could not tell.
 */
class DirectionCorrection extends Model
{
    protected $fillable = [
        'vehicle_event_id',
        'visitor_record_id',
        'vehicle_crossing_id',
        'gate',
        'detected_direction',
        'corrected_direction',
        'detected_reason',
        'corrected_by',
    ];

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
