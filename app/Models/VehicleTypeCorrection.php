<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A2 (detection): a vehicle type a guard corrected (detected -> corrected).
 */
class VehicleTypeCorrection extends Model
{
    protected $fillable = ['visitor_record_id', 'vehicle_crossing_id', 'gate', 'detected_type', 'corrected_type', 'corrected_by'];

    public function visitorRecord(): BelongsTo
    {
        return $this->belongsTo(VisitorRecord::class);
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
