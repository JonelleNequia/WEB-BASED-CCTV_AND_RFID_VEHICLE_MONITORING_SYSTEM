<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use App\Support\VehicleCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 5 (visitor model): an Unregistered Visitor record, i.e. a vehicle the
 * camera saw cross a gate with no registered tag read. Unregistered visitors
 * are counted IN and OUT but never "inside".
 */
class VisitorRecord extends Model
{
    use StoresLocalTime;

    public const STATUS_ACTIVE = 'active';

    /** The same vehicle sent twice (new track ID, same plate within a minute). */
    public const STATUS_DUPLICATE = 'duplicate';

    /** A registered tag was read for it after all, or a false alarm. */
    public const STATUS_DISMISSED = 'dismissed';

    public const PLATE_PENDING = 'pending';

    public const PLATE_READ = 'read';

    public const PLATE_UNREADABLE = 'unreadable';

    public const PLATE_CORRECTED = 'corrected';

    public const CATEGORY = VehicleCategory::UNREGISTERED_VISITOR;

    /** Phase 8: where the record came from. */
    public const SOURCE_CAMERA = 'camera';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_LEGACY = 'legacy';

    protected $fillable = [
        'external_event_key',
        'source',
        'vehicle_crossing_id',
        'gate',
        'camera_id',
        'direction',
        'seen_at',
        'status',
        'status_note',
        'duplicate_of_id',
        'snapshot_path',
        'plate_image_path',
        'plate_status',
        'plate_number',
        'plate_key',
        'plate_confidence',
        'ocr_plate_number',
        'ocr_details_json',
        'plate_profile_id',
        'vehicle_id',
        'vehicle_type',
        'vehicle_color',
        'corrected_by',
        'corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'seen_at' => 'datetime',
            'corrected_at' => 'datetime',
            'plate_confidence' => 'float',
            'ocr_details_json' => 'array',
        ];
    }

    public function crossing(): BelongsTo
    {
        return $this->belongsTo(VehicleCrossing::class, 'vehicle_crossing_id');
    }

    public function plateProfile(): BelongsTo
    {
        return $this->belongsTo(PlateProfile::class);
    }

    /** Phase 6: the Registry vehicle this plate belongs to (registered later, or tag not read). */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function plateLabel(): string
    {
        return match ($this->plate_status) {
            self::PLATE_PENDING => 'Reading plate…',
            self::PLATE_UNREADABLE => 'Plate unreadable',
            default => (string) $this->plate_number,
        };
    }

    public function getSnapshotUrlAttribute(): ?string
    {
        return $this->publicUrl($this->snapshot_path);
    }

    public function getPlateImageUrlAttribute(): ?string
    {
        return $this->publicUrl($this->plate_image_path);
    }

    protected function publicUrl(?string $path): ?string
    {
        return $path && Storage::disk('public')->exists($path) ? Storage::disk('public')->url($path) : null;
    }
}
