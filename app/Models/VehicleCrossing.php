<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 2 (visitor model): one vehicle the camera saw cross a gate's trigger
 * line. The direction comes from which side of the line it moved to (the
 * gate's calibration says which side is IN); UNKNOWN when the track was too
 * short or never got to the other side.
 */
class VehicleCrossing extends Model
{
    public const DIRECTION_IN = 'IN';

    public const DIRECTION_OUT = 'OUT';

    public const DIRECTION_UNKNOWN = 'UNKNOWN';

    public const DIRECTIONS = [self::DIRECTION_IN, self::DIRECTION_OUT, self::DIRECTION_UNKNOWN];

    protected $fillable = [
        'gate',
        'camera_id',
        'direction',
        'direction_reason',
        'crossed_at',
        'track_id',
        'confidence',
        'vehicle_type',
        'snapshot_path',
        'external_event_key',
        'detection_metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'crossed_at' => 'datetime',
            'track_id' => 'integer',
            'confidence' => 'float',
            'detection_metadata_json' => 'array',
        ];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function scopeAtGate(Builder $query, string $gate): Builder
    {
        return $query->where('gate', $gate);
    }

    public function directionLabel(): string
    {
        return match ($this->direction) {
            self::DIRECTION_IN => 'IN',
            self::DIRECTION_OUT => 'OUT',
            default => 'Direction unknown',
        };
    }

    public function getSnapshotUrlAttribute(): ?string
    {
        if ($this->snapshot_path && Storage::disk('public')->exists($this->snapshot_path)) {
            return Storage::disk('public')->url($this->snapshot_path);
        }

        return null;
    }
}
