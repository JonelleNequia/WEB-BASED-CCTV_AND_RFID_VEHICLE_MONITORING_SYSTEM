<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class GuestVehicleObservation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'plate_text',
        'vehicle_type',
        'vehicle_color',
        'location',
        'observation_source',
        'observed_at',
        'camera_id',
        'snapshot_path',
        'notes',
        'created_by',
    ];

    /**
     * Attribute casting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
        ];
    }

    /**
     * Camera used for this observation, when available.
     */
    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    /**
     * User that created this observation.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Resolve a browser-ready image URL with fallback.
     */
    public function getSnapshotUrlAttribute(): string
    {
        if ($this->snapshot_path) {
            return Storage::disk('public')->url($this->snapshot_path);
        }

        return asset('images/placeholders/vehicle-placeholder.svg');
    }
}

