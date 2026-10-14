<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gate direction work, Phase D: one Review action (audit log): who did what
 * to which record, when, and the value before and after.
 */
class ReviewAction extends Model
{
    public const LABELS = [
        'confirm' => 'Confirmed the plate',
        'correct_plate' => 'Corrected the plate',
        'pick_match' => 'Picked a known plate',
        'no_plate' => 'No plate visible',
        'set_direction' => 'Set the direction',
        'set_name' => 'Saved the name',
        'dismiss' => 'Dismissed',
    ];

    protected $fillable = ['visitor_record_id', 'plate_profile_id', 'action', 'from_value', 'to_value', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(VisitorRecord::class, 'visitor_record_id');
    }

    public function label(): string
    {
        return self::LABELS[$this->action] ?? ucfirst(str_replace('_', ' ', $this->action));
    }
}
