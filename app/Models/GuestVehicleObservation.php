<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class GuestVehicleObservation extends Model
{
    use HasFactory;
    use StoresLocalTime;

    /** Phase 5: a no-pass alert closed (a registered tag was read after all). */
    public const STATUS_RESOLVED = 'resolved';

    /**
     * Phase 8 (visitor model): archived rows (e.g. repeated unknown-tag reads)
     * are kept but never shown or counted. `withArchived()` includes them.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('not_archived', fn (Builder $query) => $query->whereNull('archived_at'));
    }

    public function scopeWithArchived(Builder $query): Builder
    {
        return $query->withoutGlobalScope('not_archived');
    }

    /** Phase 8: not yet converted into an Unregistered Visitor record. */
    public function scopeNotConverted(Builder $query): Builder
    {
        return $query->whereNull('visitor_record_id');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'plate_text',
        'plate_number',
        'vehicle_type',
        'vehicle_color',
        'location',
        'observation_source',
        'status',
        'observed_at',
        'camera_id',
        'external_event_key',
        'detection_metadata_json',
        'snapshot_path',
        'notes',
        'created_by',
        // Phase 8 (visitor model)
        'archived_at',
        'archive_reason',
        'visitor_record_id',
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
            'archived_at' => 'datetime',
            'detection_metadata_json' => 'array',
        ];
    }

    /**
     * Camera used for this observation, when available.
     */
    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    /** Phase 8: the Unregistered Visitor record this guest record became. */
    public function visitorRecord(): BelongsTo
    {
        return $this->belongsTo(VisitorRecord::class);
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
        if ($this->snapshot_path && Storage::disk('public')->exists($this->snapshot_path)) {
            return Storage::disk('public')->url($this->snapshot_path);
        }

        return asset('images/placeholders/vehicle-placeholder.svg');
    }
}
